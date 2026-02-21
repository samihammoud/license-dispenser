<?php
$available_dir = __DIR__ . '/available/';
$used_dir      = __DIR__ . '/used/';
$builds_dir    = __DIR__ . '/builds/';
$devkit_base   = __DIR__ . '/dev-kit/';

// Ensure output dirs exist
@is_dir($builds_dir) || @mkdir($builds_dir, 0775, true);
@is_dir($used_dir)    || @mkdir($used_dir, 0775, true);

$slug = $_GET['slug'] ?? '';
if (!$slug) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing slug parameter.']);
    exit;
}

// Basic slug sanity to avoid path traversal
if (!preg_match('/^[A-Za-z0-9._-]+$/', $slug)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid slug format.']);
    exit;
}

$devkit_dir      = rtrim($devkit_base . $slug, '/') . '/';
$devkit_zip_file = $devkit_base . $slug . '.zip';

// derive platform from slug (e.g., foo-NVIDIA-bar -> NVIDIA)
$parts    = explode('-', $slug);
$platform = $parts[1] ?? '';
$license_dir = rtrim($available_dir . $platform, '/') . '/';

// Validate dev kit exists either as folder or as pre-zipped file
if (!is_dir($devkit_dir) && !is_file($devkit_zip_file)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => "Dev kit not found as folder ($devkit_dir) or zip ($devkit_zip_file)"]);
    exit;
}

// Get available license
$licenses = glob($license_dir . '*.txt');
if (empty($licenses)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No available license files.']);
    exit;
}

$license_path     = $licenses[0];
$license_filename = basename($license_path);
$license_contents = @file_get_contents($license_path);
if ($license_contents === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not read license file.']);
    exit;
}

// Move license to used (reserve it)
if (!@rename($license_path, $used_dir . $license_filename)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not move license to used directory.']);
    exit;
}

// Build ZIP
$zip      = new ZipArchive();
$zip_name = "devkit_{$slug}_" . uniqid() . ".zip";
$zip_path = $builds_dir . $zip_name;

if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not create ZIP file.']);
    exit;
}

// Add dev kit: if folder -> traverse; if pre-zipped -> add the .zip as-is
if (is_dir($devkit_dir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($devkit_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if (!$file->isDir()) {
            $filePath     = $file->getRealPath();
            $relativePath = 'dev-kit/' . ltrim(substr($filePath, strlen($devkit_dir)), '/');
            $zip->addFile($filePath, $relativePath);
        }
    }
} else {
    // Pre-zipped dev kit: just drop the .zip inside the build zip
    $zip->addFile($devkit_zip_file, 'dev-kit/' . basename($devkit_zip_file));
}

// Add license
$zip->addFile($used_dir . $license_filename, 'license/' . $license_filename);

// Close archive
$zip->close();

// Return JSON with zip filename
header('Content-Type: application/json');
echo json_encode([
    'success'  => true,
    'zip_name' => $zip_name
]);
exit;
