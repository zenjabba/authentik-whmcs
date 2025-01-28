<?php
/**
 * Authentik WHMCS Provisioning Module
 *
 * This module enables automatic user provisioning and management in Authentik
 * through WHMCS. It handles user creation, suspension, and termination based
 * on WHMCS service status changes.
 *
 * @package    WHMCS
 * @author     Your Name
 * @copyright  Copyright (c) 2024
 * @license    Your License
 * @version    1.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/helpers.php';

/**
 * Module metadata.
 *
 * @return array
 */
function authentik_MetaData()
{
    return array(
        'DisplayName' => 'Authentik',
        'APIVersion' => '1.1',
        'RequiresServer' => true
    );
}

/**
 * Define module configuration options
 *
 * @return array Module configuration options
 */
function authentik_ConfigOptions() {
    return [
        'authentik_url' => [
            'FriendlyName' => 'Authentik URL',
            'Type' => 'text',
            'Size' => '255',
            'Description' => 'Enter your Authentik instance URL (e.g., https://authentik.example.com)',
        ],
        'api_token' => [
            'FriendlyName' => 'API Token',
            'Type' => 'password',
            'Size' => '255',
            'Description' => 'Enter your Authentik API token',
        ],
        'group_name' => [
            'FriendlyName' => 'Group Name',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'stash',
            'Description' => 'Enter the Authentik group name to add users to',
        ],
    ];
}

/**
 * Create a new user account in Authentik
 *
 * This function is called when a new service is activated in WHMCS.
 * It creates a new user in Authentik and assigns them to the specified group.
 *
 * @param array $params Module configuration parameters
 * @return string Success or error message
 */
function authentik_CreateAccount(array $params) {
    try {
        $baseUrl = $params['configoption1'];
        $token = $params['configoption2'];
        $groupName = $params['configoption3'];
        
        // Generate unique username
        $username = generateUniqueUsername($params);
        
        // Generate a secure random password
        $password = generateStrongPassword();

        // Store credentials in tblhosting without encryption
        // WHMCS will handle the encryption internally
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update([
                'username' => $username,
                'password' => $password  // WHMCS will encrypt this automatically
            ]);

        // Create user in Authentik
        $createUserUrl = rtrim($baseUrl, '/') . '/api/v3/core/users/';
        
        $userData = [
            'username' => $username,
            'email' => $params['clientsdetails']['email'],
            'name' => $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
            'path' => 'if/flow/initial-setup',
            'is_active' => true,
            'attributes' => [
                'settings' => [
                    'mfa_required' => true,
                    'mfa_method_preferred' => 'totp'
                ]
            ]
        ];

        // Set password separately to ensure it's included in the request
        $passwordData = [
            'password' => $password
        ];
        $userData = array_merge($userData, $passwordData);

        // Debug log to verify password is in request (mask actual password)
        logModuleCall(
            'authentik',
            'PasswordVerification',
            [
                'has_password' => isset($userData['password']),
                'password_length' => strlen($userData['password']),
                'request_data' => array_merge($userData, ['password' => '********'])
            ],
            'Verifying password is in request',
            null
        );

        // Log the user creation request (mask password in logs)
        logModuleCall(
            'authentik',
            'CreateUser_Request',
            [
                'url' => $createUserUrl,
                'data' => array_merge($userData, ['password' => '********'])
            ],
            'Attempting to create user',
            null
        );

        // Create user API call
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $createUserUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($userData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log the response (without sensitive data)
        logModuleCall(
            'authentik',
            'CreateUser_Response',
            [
                'httpCode' => $httpCode,
                'response' => $response
            ],
            'User creation response received',
            null
        );

        if ($httpCode !== 201) {
            throw new Exception('Failed to create user: ' . $response);
        }

        // Get the user ID from the response
        $userData = json_decode($response, true);
        if (!isset($userData['pk'])) {
            throw new Exception('Invalid response from Authentik API');
        }

        $userId = $userData['pk'];

        // Now add user to group
        $groupUrl = rtrim($baseUrl, '/') . '/api/v3/core/groups/?name=' . urlencode($groupName);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $groupUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $groups = json_decode($response, true);
        if (!isset($groups['results']) || empty($groups['results'])) {
            throw new Exception("Group '{$groupName}' not found");
        }

        $groupId = $groups['results'][0]['pk'];

        // Add user to group using the correct endpoint
        $addToGroupUrl = rtrim($baseUrl, '/') . '/api/v3/core/groups/' . $groupId . '/add_user/';
        $groupData = [
            'pk' => $userId
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $addToGroupUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($groupData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        logModuleCall(
            'authentik',
            'AddUserToGroup',
            [
                'url' => $addToGroupUrl,
                'userId' => $userId,
                'groupId' => $groupId
            ],
            $response,
            "HTTP Code: {$httpCode}"
        );

        if ($httpCode === 204 || $httpCode === 200 || $httpCode === 201) {
            // Send welcome email with credentials
            $command = 'SendEmail';
            $postData = array(
                'messagename' => 'Authentik Account Details',
                'id' => $params['serviceid'],
                'customtype' => 'product',
                'customsubject' => 'Your Authentik Account Details',
                'customvars' => base64_encode(serialize(array(
                    'client_name' => $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
                    'authentik_url' => rtrim($baseUrl, '/'),
                    'username' => $username,
                    'password' => $password,
                ))),
            );

            $results = localAPI($command, $postData);
            
            logModuleCall(
                'authentik',
                'SendEmail',
                array_merge($postData, ['customvars' => '********']),
                $results,
                'Sending welcome email'
            );
            
            return 'success';
        }

        throw new Exception("Failed to add user to group. HTTP Code: " . $httpCode . ($response ? ". Response: " . $response : ""));

    } catch (Exception $e) {
        logModuleCall(
            'authentik',
            'CreateAccount_Error',
            [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ],
            null,
            null
        );
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Terminate a user account in Authentik
 *
 * This function is called when a service is terminated in WHMCS.
 * It deletes the user from Authentik.
 *
 * @param array $params Module configuration parameters
 * @return string Success or error message
 */
function authentik_TerminateAccount(array $params) {
    try {
        $baseUrl = $params['configoption1'];
        $token = $params['configoption2'];
        
        // Get the stored username from tblhosting
        $username = Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->value('username');

        if (!$username) {
            throw new Exception('Could not find stored username for service');
        }

        // Log the termination attempt
        logModuleCall(
            'authentik',
            'TerminateAccount',
            [
                'username' => $username
            ],
            'Attempting to terminate account',
            null
        );

        // First, get the user ID
        $userUrl = rtrim($baseUrl, '/') . '/api/v3/core/users/?username=' . urlencode($username);
        
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
        curl_close($ch);

        $userData = json_decode($response, true);
        
        // Log the user search response
        logModuleCall(
            'authentik',
            'FindUser',
            [
                'url' => $userUrl,
                'response' => $userData,
                'httpCode' => $httpCode
            ],
            'Searching for user',
            null
        );

        if (!isset($userData['results']) || empty($userData['results'])) {
            throw new Exception('Failed to find user: ' . $username);
        }

        $userId = $userData['results'][0]['pk'];

        // Now delete the user
        $deleteUrl = rtrim($baseUrl, '/') . '/api/v3/core/users/' . $userId . '/';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $deleteUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log the deletion response
        logModuleCall(
            'authentik',
            'DeleteUser',
            [
                'url' => $deleteUrl,
                'httpCode' => $httpCode,
                'response' => $response
            ],
            'User deletion response',
            null
        );

        if ($httpCode === 204 || $httpCode === 200) {
            return 'success';
        }

        throw new Exception('Failed to delete user. HTTP Code: ' . $httpCode . ($response ? ". Response: " . $response : ""));

    } catch (Exception $e) {
        logModuleCall(
            'authentik',
            'Error',
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Suspend a user account in Authentik
 *
 * This function is called when a service is suspended in WHMCS.
 * It deactivates the user in Authentik.
 *
 * @param array $params Module configuration parameters
 * @return string Success or error message
 */
function authentik_SuspendAccount(array $params) {
    try {
        $baseUrl = $params['configoption1'];
        $token = $params['configoption2'];
        
        // Get the stored username from tblhosting
        $username = Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->value('username');

        if (!$username) {
            throw new Exception('Could not find stored username for service');
        }

        // Log the suspension attempt
        logModuleCall(
            'authentik',
            'SuspendAccount',
            [
                'username' => $username
            ],
            'Attempting to suspend account',
            null
        );

        // First, get the user ID
        $userUrl = rtrim($baseUrl, '/') . '/api/v3/core/users/?username=' . urlencode($username);
        
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
        curl_close($ch);

        $userData = json_decode($response, true);
        
        // Log the user search response
        logModuleCall(
            'authentik',
            'FindUser',
            [
                'url' => $userUrl,
                'response' => $userData,
                'httpCode' => $httpCode
            ],
            'Searching for user',
            null
        );

        if (!isset($userData['results']) || empty($userData['results'])) {
            throw new Exception('Failed to find user: ' . $username);
        }

        $userId = $userData['results'][0]['pk'];

        // Deactivate the user
        $updateUrl = rtrim($baseUrl, '/') . '/api/v3/core/users/' . $userId . '/';
        
        $updateData = [
            'is_active' => false
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $updateUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode($updateData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log the deactivation response
        logModuleCall(
            'authentik',
            'DeactivateUser',
            [
                'url' => $updateUrl,
                'data' => $updateData,
                'httpCode' => $httpCode,
                'response' => $response
            ],
            'User deactivation response',
            null
        );

        if ($httpCode === 200) {
            return 'success';
        }

        throw new Exception("Failed to deactivate user. HTTP Code: " . $httpCode . ($response ? ". Response: " . $response : ""));

    } catch (Exception $e) {
        logModuleCall(
            'authentik',
            'Error',
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return 'Error: ' . $e->getMessage();
    }
}

// End of file