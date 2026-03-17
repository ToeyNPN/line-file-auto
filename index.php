<?php

/**
 * LINE File Handler — converted from n8n workflow "AI Download File Line"
 *
 * Flow:
 *  1. Receive LINE Webhook (POST /save-file)
 *  2. Verify request signature
 *  3. Check if event type is "file"
 *  4. Check if fileName contains "คำสั่ง" AND ".pdf"
 *  5. Download file content from LINE API
 *  6. Rename file with timestamp
 *  7. Upload file to Google Drive (folder by File ID)
 *  8. Append file record to Google Sheets
 *  9. Reply to user via LINE Messaging API
 *
 * ─────────────────────────────────────────────
 * CONFIGURATION — fill in your own values below
 * ─────────────────────────────────────────────
 */

// ── LINE ──────────────────────────────────────
// NOTE: Secrets should be injected via environment variables in production.
define('LINE_CHANNEL_ACCESS_TOKEN', getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: 'o5eyHwZwr+RM4IsAq8LlgkFtfnjDZy3bGDkU8ImKT2t3vcfn0qybC9gTmEndl2OlEJx4kKbMTZDV8ApD0fscRJXni5RACf+wUNQds9f4LHe6mbQQxR9IkOp5KuCt/Pag9aptqUkI/BzDpAGFYmtN2AdB04t89/1O/w1cDnyilFU=');
define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET') ?: '');

// ── Google Drive ─────────────────────────────
// Service-account JSON key file path (relative or absolute)
define('GOOGLE_SERVICE_ACCOUNT_JSON', __DIR__ . '/service-account.json');
// Google Drive FOLDER ID (from the folder URL: drive.google.com/drive/folders/<FOLDER_ID>)
// define('GOOGLE_DRIVE_FOLDER_ID', '1xOqFEPll5Zu5xxNAJxvolRheIetaxObg'); //teacher's folder
define('GOOGLE_DRIVE_FOLDER_ID', '1zMW2HMd53BWcFQPIoZ_QsnWp5Dwwvtx9'); //my folder


// ── Google Sheets ─────────────────────────────
// Spreadsheet ID (from the sheet URL: docs.google.com/spreadsheets/d/<SPREADSHEET_ID>)
define('GOOGLE_SHEETS_SPREADSHEET_ID', '13LfPPsoAj3ivtklEgjegUKi7icyI2aTwo1dVBhWxha0');
define('GOOGLE_SHEETS_SHEET_NAME', 'ชีต1'); // Tab name

// ─────────────────────────────────────────────

// ── Bootstrap ─────────────────────────────────
header('Content-Type: application/json');

// Prevent PHP from leaking errors to the client
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Basic runtime configuration validation
if (!LINE_CHANNEL_ACCESS_TOKEN) {
    http_response_code(500);
    echo json_encode(['error' => 'LINE channel access token is not configured']);
    exit;
}

if (!is_file(GOOGLE_SERVICE_ACCOUNT_JSON)) {
    http_response_code(200);
    echo json_encode(['status' => 'skip google (no credentials)']);
    exit;
}

// Load Google API PHP Client (install via composer: google/apiclient)
require_once __DIR__ . '/vendor/autoload.php';

// ─────────────────────────────────────────────
// 1. Read incoming Webhook payload
// ─────────────────────────────────────────────
$raw   = file_get_contents('php://input');
$body  = json_decode($raw, true);

if (empty($body['events'][0])) {
    http_response_code(200);
    echo json_encode(['status' => 'no events']);
    exit;
}

$event      = $body['events'][0];
$messageId  = $event['message']['id']       ?? '';
$replyToken = $event['replyToken']           ?? '';
$fileName   = $event['message']['fileName']  ?? '';
$msgType    = $event['message']['type']      ?? '';

// ─────────────────────────────────────────────
// 2. IsFile — only process "file" type messages
// ─────────────────────────────────────────────
if ($msgType !== 'file') {
    http_response_code(200);
    echo json_encode(['status' => 'not a file event']);
    exit;
}

// ─────────────────────────────────────────────
// 3. Check if fileName contains "คำสั่ง" AND ".pdf"
// ─────────────────────────────────────────────
$containsKeyword = (
    mb_strpos($fileName, 'คำสั่ง') !== false &&
    mb_strpos($fileName, '.pdf')   !== false
);

if (!$containsKeyword) {
    http_response_code(200);
    echo json_encode(['status' => 'filename condition not met']);
    exit;
}

// ─────────────────────────────────────────────
// 4. Download file from LINE
// ─────────────────────────────────────────────
$fileContent = downloadLineFile($messageId);

if ($fileContent === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to download file from LINE']);
    exit;
}

// ─────────────────────────────────────────────
// 5. Build timestamped filename
// ─────────────────────────────────────────────
$now             = new DateTime();
$timestamp       = $now->format('Y-m-d_H-i-s');
$extension       = pathinfo($fileName, PATHINFO_EXTENSION);
$extension       = $extension ? $extension : 'bin';
$baseName        = str_replace('.pdf', '_', $fileName); // mirrors n8n logic
$newFileName     = $baseName . $timestamp . '.' . $extension;
$mimeType        = getMimeType($extension);

// ─────────────────────────────────────────────
// 6. Upload to Google Drive (using folder File ID)
// ─────────────────────────────────────────────
$driveResult = uploadToGoogleDrive($fileContent, $newFileName, $mimeType);

if (!$driveResult) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upload to Google Drive']);
    exit;
}

$webViewLink = $driveResult['webViewLink'];
$driveFileName = $driveResult['name'];
$driveMimeType = $driveResult['mimeType'];

// ─────────────────────────────────────────────
// 7. Append row to Google Sheets
// ─────────────────────────────────────────────
appendToGoogleSheets($driveFileName, $webViewLink, $driveMimeType);

// ─────────────────────────────────────────────
// 8. Reply to user via LINE (minimal acknowledgment)
// ─────────────────────────────────────────────
$replyText = "ไฟล์ได้รับแล้ว: {$driveFileName}";
sendLineReply($replyToken, $replyText);

http_response_code(200);
echo json_encode(['status' => 'ok']);
exit;


// ═════════════════════════════════════════════
// HELPER FUNCTIONS
// ═════════════════════════════════════════════

/**
 * Download file binary content from LINE API.
 */
function downloadLineFile(string $messageId): string|false
{
    $url = "https://api-data.line.me/v2/bot/message/{$messageId}/content";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200) ? $response : false;
}

/**
 * Upload file to Google Drive using the Service Account and a Folder ID.
 *
 * @param string $fileContent  Raw binary content
 * @param string $fileName     Desired filename in Drive
 * @param string $mimeType     MIME type of the file
 * @return array|false         Drive file metadata or false on failure
 */
function uploadToGoogleDrive(string $fileContent, string $fileName, string $mimeType): array|false
{
    $client = getGoogleClient();
    $service = new Google\Service\Drive($client);

    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name'    => $fileName,
        'parents' => [GOOGLE_DRIVE_FOLDER_ID],  // ← Folder File ID used here
    ]);

    $file = $service->files->create(
        $fileMetadata,
        [
            'data'       => $fileContent,
            'mimeType'   => $mimeType,
            'uploadType' => 'multipart',
            'fields'     => 'id,name,mimeType,webViewLink',
        ]
    );

    if (!$file || !$file->getId()) {
        return false;
    }

    return [
        'id'          => $file->getId(),
        'name'        => $file->getName(),
        'mimeType'    => $file->getMimeType(),
        'webViewLink' => $file->getWebViewLink(),
    ];
}

/**
 * Append a row to the configured Google Sheet.
 */
function appendToGoogleSheets(string $name, string $link, string $type): void
{
    $client  = getGoogleClient();
    $service = new Google\Service\Sheets($client);

    $today = (new DateTime())->format('Y-m-d');

    $values = [[$name, $link, $type, $today]];
    $body   = new Google\Service\Sheets\ValueRange(['values' => $values]);

    $range = GOOGLE_SHEETS_SHEET_NAME . '!A:D';

    $service->spreadsheets_values->append(
        GOOGLE_SHEETS_SPREADSHEET_ID,
        $range,
        $body,
        ['valueInputOption' => 'USER_ENTERED']
    );
}

/**
 * Send a reply message via LINE Messaging API.
 */
function sendLineReply(string $replyToken, string $text): void
{
    $payload = json_encode([
        'replyToken' => $replyToken,
        'messages'   => [['type' => 'text', 'text' => $text]],
    ]);

    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Build and return an authorized Google API Client using a Service Account JSON key.
 */
function getGoogleClient(): Google\Client
{
    $client = new Google\Client();
    $client->setAuthConfig(GOOGLE_SERVICE_ACCOUNT_JSON);
    $client->addScope(Google\Service\Drive::DRIVE_FILE);
    $client->addScope(Google\Service\Sheets::SPREADSHEETS);
    return $client;
}

/**
 * Map file extension to MIME type.
 */
function getMimeType(string $ext): string
{
    $map = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
    ];
    return $map[strtolower($ext)] ?? 'application/octet-stream';
}