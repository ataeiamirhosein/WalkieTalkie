<?php
declare(strict_types=1);

// Start output buffering
ob_start();

// Set JSON header
header('Content-Type: application/json; charset=UTF-8');

// Configuration
define('BASE_DIR', __DIR__);
define('AUDIO_DIR', BASE_DIR . '/audio/');
define('CONNECTIONS_DIR', BASE_DIR . '/connections/');
define('INACTIVITY_TIMEOUT', 30); // seconds

// Error handling
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Create directories if needed
function ensureDirectoryExists(string $path): void {
    if (!file_exists($path) && !mkdir($path, 0755, true)) {
        throw new RuntimeException("Failed to create directory: $path");
    }
    if (!is_writable($path)) {
        throw new RuntimeException("Directory not writable: $path");
    }
}

// Main error handler
function sendResponse(bool $success, $data = null): void {
    // Clean any previous output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $response = [
        'success' => $success,
        'timestamp' => time()
    ];

    if ($success) {
        $response['data'] = $data;
    } else {
        $response['error'] = is_string($data) ? $data : 'An error occurred';
    }

    echo json_encode($response);
    exit;
}

try {
    // Ensure directories exist
    ensureDirectoryExists(AUDIO_DIR);
    ensureDirectoryExists(CONNECTIONS_DIR);

    // Get and validate action
    $action = $_GET['action'] ?? '';
    $validActions = ['connect', 'disconnect', 'upload', 'check', 'delete', 'test', 'debug_connections'];
    if (!in_array($action, $validActions)) {
        sendResponse(false, 'Invalid action specified');
    }

    // Process the action
    $result = null;
    switch ($action) {
        case 'test':
            $result = [
                'status' => 'OK',
                'audio_dir_writable' => is_writable(AUDIO_DIR),
                'connections_dir_writable' => is_writable(CONNECTIONS_DIR),
                'php_version' => phpversion()
            ];
            break;
            
        case 'debug_connections':
            $connections = [];
            $files = glob(CONNECTIONS_DIR . '*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                $connections[basename($file)] = [
                    'data' => $data,
                    'valid' => $data['timestamp'] >= time() - INACTIVITY_TIMEOUT,
                    'modified' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
            $result = [
                'connections' => $connections,
                'audio_dirs' => glob(AUDIO_DIR . 'pair_*'),
                'server_time' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'connect':
            $result = handleConnect();
            break;
            
        case 'disconnect':
            $result = handleDisconnect();
            break;
            
        case 'upload':
            $result = handleUpload();
            break;
            
        case 'check':
            $result = handleCheck();
            break;
            
        case 'delete':
            $result = handleDelete();
            break;
    }

    sendResponse(true, $result);

} catch (Throwable $e) {
    error_log('API Error: ' . $e->getMessage());
    sendResponse(false, 'Internal server error');
}

// Handler functions
function handleConnect(): array {
    $myId = sanitizeId($_GET['my_id'] ?? '');
    $partnerId = sanitizeId($_GET['partner_id'] ?? '');

    if (empty($myId) || empty($partnerId)) {
        throw new InvalidArgumentException('Both user IDs are required');
    }

    $pairId = getPairId($myId, $partnerId);
    $partnerFile = CONNECTIONS_DIR . $partnerId . '.json';
    $myConnectionFile = CONNECTIONS_DIR . $myId . '.json';

    // Clean up any existing connection file for this user
    if (file_exists($myConnectionFile)) {
        $existingData = json_decode(file_get_contents($myConnectionFile), true);
        // Only cleanup if the existing connection is expired
        if ($existingData['timestamp'] < time() - INACTIVITY_TIMEOUT) {
            unlink($myConnectionFile);
        } elseif ($existingData['partner_id'] !== $partnerId) {
            // Clean up if connecting to a different partner
            unlink($myConnectionFile);
        }
    }

    // Check if partner is already connected to someone else
    if (file_exists($partnerFile)) {
        $partnerData = json_decode(file_get_contents($partnerFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Clean up corrupt partner file
            unlink($partnerFile);
        } elseif ($partnerData['partner_id'] !== $myId) {
            // Partner is connected to someone else
            if ($partnerData['timestamp'] >= time() - INACTIVITY_TIMEOUT) {
                throw new RuntimeException('Partner is already connected to someone else');
            } else {
                // Partner connection expired, clean it up
                unlink($partnerFile);
            }
        }
    }

    // Create connection file with LOCK_EX to prevent race conditions
    $connectionData = [
        'user_id' => $myId,
        'partner_id' => $partnerId,
        'pair_id' => $pairId,
        'timestamp' => time()
    ];

    if (file_put_contents($myConnectionFile, json_encode($connectionData), LOCK_EX) === false) {
        throw new RuntimeException('Failed to save connection file');
    }

    // Create pair directory if it doesn't exist
    $pairDir = getPairDirectory($pairId);
    if (!file_exists($pairDir)) {
        if (!mkdir($pairDir, 0755, true)) {
            // Clean up connection file if directory creation fails
            unlink($myConnectionFile);
            throw new RuntimeException('Failed to create pair directory');
        }
    }

    return ['message' => 'Connection established'];
}

function handleDisconnect(): array {
    $myId = sanitizeId($_GET['my_id'] ?? '');
    if (empty($myId)) {
        throw new InvalidArgumentException('User ID is required');
    }

    $connectionFile = CONNECTIONS_DIR . $myId . '.json';
    if (file_exists($connectionFile)) {
        // Get connection data before deleting
        $connectionData = json_decode(file_get_contents($connectionFile), true);
        
        // Delete connection file
        if (!unlink($connectionFile)) {
            throw new RuntimeException('Failed to delete connection file');
        }
        
        // Clean up old audio files in pair directory
        if (isset($connectionData['pair_id'])) {
            $pairDir = getPairDirectory($connectionData['pair_id']);
            if (file_exists($pairDir)) {
                $files = glob($pairDir . '*.webm');
                foreach ($files as $file) {
                    if (file_exists($file)) {
                        unlink($file);
                    }
                }
                
                // Try to remove the directory if empty
                @rmdir($pairDir);
            }
        }
    }

    return ['message' => 'Disconnected successfully'];
}

function handleUpload(): array {
    $myId = sanitizeId($_POST['my_id'] ?? '');
    $partnerId = sanitizeId($_POST['partner_id'] ?? '');

    if (empty($myId) || empty($partnerId)) {
        throw new InvalidArgumentException('Both user IDs are required');
    }

    if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Audio file upload failed');
    }

    // Verify the connection still exists
    $connectionFile = CONNECTIONS_DIR . $myId . '.json';
    if (!file_exists($connectionFile)) {
        throw new RuntimeException('Connection does not exist');
    }

    $connectionData = json_decode(file_get_contents($connectionFile), true);
    if (json_last_error() !== JSON_ERROR_NONE || $connectionData['partner_id'] !== $partnerId) {
        throw new RuntimeException('Invalid connection');
    }

    $pairId = $connectionData['pair_id'];
    $pairDir = getPairDirectory($pairId);
    ensureDirectoryExists($pairDir);

    $filename = 'msg_' . $myId . '_' . time() . '.webm';
    $filepath = $pairDir . $filename;

    if (!move_uploaded_file($_FILES['audio']['tmp_name'], $filepath)) {
        throw new RuntimeException('Failed to save audio file');
    }

    updateConnectionTimestamp($myId);

    return [
        'filename' => $filename,
        'filepath' => 'audio/' . $pairId . '/' . $filename
    ];
}

function handleCheck(): array {
    $myId = sanitizeId($_GET['my_id'] ?? '');
    $partnerId = sanitizeId($_GET['partner_id'] ?? '');

    if (empty($myId) || empty($partnerId)) {
        throw new InvalidArgumentException('Both user IDs are required');
    }

    $connectionFile = CONNECTIONS_DIR . $myId . '.json';
    $connectionActive = false;

    if (file_exists($connectionFile)) {
        $connectionData = json_decode(file_get_contents($connectionFile), true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            // Verify the connection is still valid
            if ($connectionData['partner_id'] === $partnerId) {
                if ($connectionData['timestamp'] >= time() - INACTIVITY_TIMEOUT) {
                    $connectionActive = true;
                    // Update timestamp only if connection is active
                    updateConnectionTimestamp($myId);
                } else {
                    // Connection expired - clean up
                    unlink($connectionFile);
                }
            } else {
                // Partner ID mismatch - clean up
                unlink($connectionFile);
            }
        } else {
            // Invalid JSON - clean up
            unlink($connectionFile);
        }
    }

    // Check for audio files if connection is active
    $audioData = null;
    if ($connectionActive && isset($connectionData['pair_id'])) {
        $pairDir = getPairDirectory($connectionData['pair_id']);
        if (file_exists($pairDir)) {
            $audioFiles = glob($pairDir . 'msg_' . $partnerId . '_*.webm');
            if (!empty($audioFiles)) {
                usort($audioFiles, fn($a, $b) => filemtime($b) - filemtime($a));
                $latestFile = $audioFiles[0];
                
                // Verify file is not too old (e.g., older than 5 minutes)
                if (filemtime($latestFile) >= time() - 300) {
                    $audioData = 'audio/' . $connectionData['pair_id'] . '/' . basename($latestFile);
                } else {
                    // Clean up old file
                    unlink($latestFile);
                }
            }
        }
    }

    return [
        'connection_active' => $connectionActive,
        'audio' => $audioData
    ];
}

function handleDelete(): array {
    $file = $_GET['file'] ?? '';
    if (empty($file)) {
        throw new InvalidArgumentException('File parameter is required');
    }

    // Security check
    $realPath = realpath(BASE_DIR . '/' . $file);
    $audioDir = realpath(AUDIO_DIR);

    if (strpos($realPath, $audioDir) !== 0 || !file_exists($realPath)) {
        throw new RuntimeException('Invalid file path');
    }

    if (!unlink($realPath)) {
        throw new RuntimeException('Failed to delete file');
    }

    return ['message' => 'File deleted successfully'];
}

// Utility functions
function sanitizeId(string $id): string {
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if (empty($sanitized)) {
        throw new InvalidArgumentException('Invalid user ID format');
    }
    return $sanitized;
}

function getPairId(string $id1, string $id2): string {
    $ids = [$id1, $id2];
    sort($ids);
    return 'pair_' . implode('_', $ids);
}

function getPairDirectory(string $pairId): string {
    return AUDIO_DIR . $pairId . '/';
}

function updateConnectionTimestamp(string $userId): void {
    $connectionFile = CONNECTIONS_DIR . $userId . '.json';
    if (file_exists($connectionFile)) {
        // Use file locking to prevent race conditions
        $fp = fopen($connectionFile, 'r+');
        if (flock($fp, LOCK_EX)) {
            $data = json_decode(file_get_contents($connectionFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data['timestamp'] = time();
                ftruncate($fp, 0);
                fseek($fp, 0);
                fwrite($fp, json_encode($data));
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}