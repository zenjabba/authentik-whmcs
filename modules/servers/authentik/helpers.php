<?php

function generateUsername() {
    // Arrays of words to create combinations from
    $adjectives = [
        // Colors
        'amber', 'azure', 'blue', 'bronze', 'coral', 'crimson', 'cyan', 'gold', 'green', 'indigo',
        'jade', 'lime', 'magenta', 'maroon', 'navy', 'olive', 'orange', 'purple', 'red', 'silver',
        'teal', 'violet', 'white', 'yellow',
        
        // Qualities
        'swift', 'bright', 'cool', 'dark', 'easy', 'fast', 'good', 'happy', 'light', 'lucky',
        'mega', 'nice', 'prime', 'quick', 'rapid', 'safe', 'tech', 'ultra', 'vivid', 'wise',
        'brave', 'calm', 'eager', 'fierce', 'gentle', 'keen', 'bold', 'smart', 'strong', 'wild',
        
        // Tech-related
        'alpha', 'beta', 'cyber', 'delta', 'echo', 'flux', 'gamma', 'hyper', 'ionic', 'jazz',
        'lunar', 'micro', 'nexus', 'omega', 'pixel', 'quad', 'ruby', 'solar', 'turbo', 'vector',
        'binary', 'crypto', 'digital', 'quantum', 'neural', 'plasma', 'sonic', 'static', 'virtual',
        
        // Nature
        'storm', 'frost', 'rain', 'wind', 'cloud', 'snow', 'ice', 'flame', 'sun', 'star',
        'moon', 'dawn', 'dusk', 'nova', 'cosmic', 'ocean', 'river', 'forest', 'mountain', 'desert'
    ];

    $nouns = [
        // Tech terms
        'air', 'base', 'byte', 'code', 'data', 'edge', 'file', 'grid', 'hash', 'icon',
        'jump', 'key', 'link', 'mail', 'node', 'path', 'quad', 'root', 'sync', 'task',
        'user', 'void', 'wave', 'xray', 'zone', 'app', 'bit', 'cap', 'disk', 'echo',
        'flow', 'gate', 'host', 'info', 'jack', 'kit', 'log', 'map', 'net', 'port',
        
        // Computing
        'ram', 'cpu', 'gpu', 'ssd', 'lan', 'wan', 'dns', 'ip', 'ssl', 'ssh',
        'ftp', 'http', 'ping', 'boot', 'core', 'raid', 'bios', 'cache', 'chip', 'cloud',
        'dock', 'fork', 'heap', 'host', 'loop', 'menu', 'mime', 'null', 'pipe', 'pool',
        
        // Abstract
        'mind', 'soul', 'zeit', 'form', 'pulse', 'spark', 'storm', 'void', 'ward', 'zero',
        'apex', 'arch', 'core', 'dome', 'edge', 'flex', 'fold', 'fork', 'gate', 'hub',
        
        // Objects
        'beam', 'bolt', 'cube', 'disk', 'dome', 'gear', 'grid', 'lens', 'node', 'orb',
        'ring', 'seed', 'tank', 'tube', 'wire', 'zone', 'arc', 'box', 'coil', 'deck'
    ];

    // Get random words
    $adjective = $adjectives[array_rand($adjectives)];
    $noun = $nouns[array_rand($nouns)];
    $number = mt_rand(1000, 99999);

    return strtolower($adjective . $noun . $number);
}

function checkUsernameInAuthentik($username, $params) {
    try {
        $baseUrl = rtrim($params['configoption1'], '/');
        $token = $params['configoption2'];

        $userUrl = $baseUrl . '/api/v3/core/users/?username=' . urlencode($username);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $userUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Log the check for debugging
        logModuleCall(
            'authentik',
            'CheckUsername',
            [
                'username' => $username,
                'url' => $userUrl,
            ],
            [
                'httpCode' => $httpCode,
                'response' => $response,
                'curlError' => $curlError
            ],
            null
        );

        if ($httpCode !== 200) {
            throw new Exception('Failed to check username. HTTP Code: ' . $httpCode . '. Error: ' . $curlError);
        }

        $result = json_decode($response, true);
        
        // If results is empty, username is available
        return empty($result['results']);

    } catch (Exception $e) {
        logModuleCall(
            'authentik',
            'CheckUsername_Error',
            [
                'username' => $username,
                'error' => $e->getMessage()
            ],
            null,
            null
        );
        throw $e;
    }
}

function generateUniqueUsername($params) {
    $maxAttempts = 50; // Increased from 10 to handle more retries
    $attempts = 0;
    $usedUsernames = [];

    while ($attempts < $maxAttempts) {
        $username = generateUsername();
        $attempts++;

        // Log attempt for debugging
        logModuleCall(
            'authentik',
            'GenerateUsername_Attempt',
            [
                'attempt' => $attempts,
                'username' => $username
            ],
            null,
            null
        );

        // Skip if we've already tried this username
        if (in_array($username, $usedUsernames)) {
            continue;
        }

        $usedUsernames[] = $username;

        try {
            if (checkUsernameInAuthentik($username, $params)) {
                // Log successful generation
                logModuleCall(
                    'authentik',
                    'GenerateUsername_Success',
                    [
                        'attempts' => $attempts,
                        'finalUsername' => $username
                    ],
                    null,
                    null
                );
                return $username;
            }
        } catch (Exception $e) {
            // Log the error but continue trying
            logModuleCall(
                'authentik',
                'GenerateUsername_Error',
                [
                    'attempt' => $attempts,
                    'error' => $e->getMessage()
                ],
                null,
                null
            );
            continue;
        }
    }

    // If we get here, we failed to generate a unique username
    throw new Exception('Failed to generate unique username after ' . $maxAttempts . ' attempts');
}

/**
 * Generate a secure random password
 * 
 * @return string A strong password containing uppercase, lowercase, numbers, and special characters
 */
function generateStrongPassword() {
    $length = 16;
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special = '!@#$%^&*()_+-=[]{}|;:,.<>?';
    
    $password = '';
    
    // Ensure at least one of each character type
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];
    
    // Fill the rest with random characters from all sets
    $all = $uppercase . $lowercase . $numbers . $special;
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }
    
    // Shuffle the password to make it more random
    return str_shuffle($password);
}

// Optional: Add a function to validate username format if needed
function isValidUsername($username) {
    // Only allow lowercase letters, numbers, and specific patterns
    return preg_match('/^[a-z]+[a-z]+\d{4,5}$/', $username);
}
 