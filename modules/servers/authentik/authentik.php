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

        // Store credentials in tblhosting (encrypt password for storage)
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update([
                'username' => $username,
                'password' => encrypt($password)  // Encrypt for storage
            ]);

        // Log the final username being used
        logModuleCall(
            'authentik',
            'CreateAccount_Username',
            [
                'username' => $username
            ],
            'Starting account creation with generated username',
            null
        );

        // Debug initial parameters
        logModuleCall(
            'authentik',
            'CreateAccount_InitialParams',
            [
                'baseUrl' => $baseUrl,
                'username' => $username,
                'groupName' => $groupName,
                'email' => $params['clientsdetails']['email'],
                'fullParams' => $params
            ],
            'Starting user creation process',
            null
        );
        
        // First create the user
        $createUserUrl = rtrim($baseUrl, '/') . '/api/v3/core/users/';
        
        $userData = [
            'username' => $username,
            'email' => $params['clientsdetails']['email'],
            'name' => $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
            'password' => $password,  // Use unencrypted password for Authentik
            'is_active' => true,
            'path' => '/if/flow/initial-setup/',  // Force user to set up 2FA
            'attributes' => [
                'settings' => [
                    'mfa_required' => true,  // Require 2FA
                    'mfa_method_preferred' => 'totp'  // Default to TOTP (Time-based One-Time Password)
                ]
            ]
        ];

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
        $curlError = curl_error($ch);
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
            logModuleCall(
                'authentik',
                'CreateUser_Error',
                [
                    'httpCode' => $httpCode,
                    'response' => $response
                ],
                'Failed to create user',
                null
            );
            return 'Failed to create user. HTTP Code: ' . $httpCode . '. Response: ' . $response;
        }

        $createdUser = json_decode($response, true);
        $userId = $createdUser['pk'];

        // Now get the group ID
        $groupsUrl = rtrim($baseUrl, '/') . '/api/v3/core/groups/?name=' . urlencode($groupName);
        
        // Log group lookup request
        logModuleCall(
            'authentik',
            'LookupGroup_Request',
            [
                'url' => $groupsUrl,
                'groupName' => $groupName
            ],
            'Attempting to find group',
            null
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $groupsUrl,
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

        // Log group lookup response
        logModuleCall(
            'authentik',
            'LookupGroup_Response',
            [
                'httpCode' => $httpCode,
                'response' => $response,
                'curlError' => $curlError
            ],
            'Group lookup response received',
            null
        );

        if ($httpCode !== 200) {
            logModuleCall(
                'authentik',
                'LookupGroup_Error',
                [
                    'httpCode' => $httpCode,
                    'response' => $response
                ],
                'Failed to find group',
                null
            );
            return "Failed to find group. HTTP Code: " . $httpCode . ". Response: " . $response;
        }

        $groups = json_decode($response, true);
        if (empty($groups['results'])) {
            logModuleCall(
                'authentik',
                'LookupGroup_NotFound',
                [
                    'groupName' => $groupName,
                    'response' => $response
                ],
                'Group not found in results',
                null
            );
            return "Group '{$groupName}' not found";
        }

        $groupId = $groups['results'][0]['pk'];

        // Add user to group using the correct API endpoint
        $addToGroupUrl = rtrim($baseUrl, '/') . '/api/v3/core/groups/' . $groupId . '/add_user/';
        $groupData = [
            'pk' => $userId
        ];

        // Log group assignment request
        logModuleCall(
            'authentik',
            'AddToGroup_Request',
            [
                'url' => $addToGroupUrl,
                'data' => $groupData
            ],
            'Attempting to add user to group',
            null
        );

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
        $curlError = curl_error($ch);
        curl_close($ch);

        // Log group assignment response
        logModuleCall(
            'authentik',
            'AddToGroup_Response',
            [
                'httpCode' => $httpCode,
                'response' => $response,
                'curlError' => $curlError
            ],
            'Group assignment response received',
            null
        );

        // HTTP 204 means success with no content, which is normal for this operation
        if ($httpCode === 204 || $httpCode === 200 || $httpCode === 201) {
            logModuleCall(
                'authentik',
                'CreateAccount_Success',
                [
                    'userId' => $userId,
                    'groupId' => $groupId
                ],
                'Successfully created user and added to group',
                null
            );

            // Send welcome email with credentials (use unencrypted password)
            $command = 'SendEmail';
            $values = array(
                'messagename' => 'Authentik Account Details',
                'id' => $params['serviceid'],
                'customtype' => 'product',
                'customsubject' => 'Your Authentik Account Details',
                'customvars' => base64_encode(serialize(array(
                    'client_name' => $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
                    'authentik_url' => rtrim($baseUrl, '/'),
                    'username' => $username,
                    'password' => $password,  // Use unencrypted password for email
                ))),
            );

            $results = localAPI($command, $values);
            
            logModuleCall(
                'authentik',
                'SendEmail',
                array_merge($values, ['customvars' => '********']),  // Mask sensitive data in logs
                $results,
                'Sending welcome email'
            );
            
            return 'success';
        }

        logModuleCall(
            'authentik',
            'AddToGroup_Error',
            [
                'httpCode' => $httpCode,
                'response' => $response
            ],
            'Failed to add user to group',
            null
        );

        return "Failed to add user to group. HTTP Code: " . $httpCode . ($response ? ". Response: " . $response : "");

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
        curl_close($ch);

        logModuleCall(
            'authentik',
            'GetUser',
            $userUrl,
            $response,
            "HTTP Code: {$httpCode}"
        );

        if ($httpCode !== 200) {
            return 'Failed to find user: ' . $response;
        }

        $users = json_decode($response, true);
        if (empty($users['results'])) {
            return 'User not found';
        }

        $userId = $users['results'][0]['pk'];

        // Delete the user
        $deleteUrl = $baseUrl . '/api/v3/core/users/' . $userId . '/';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $deleteUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        logModuleCall(
            'authentik',
            'DeleteUser',
            $deleteUrl,
            $response,
            "HTTP Code: {$httpCode}"
        );

        if ($httpCode === 204 || $httpCode === 200) {
            return 'success';
        }

        return 'Failed to delete user: ' . $response;
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
 * It removes the user from the specified group in Authentik.
 *
 * @param array $params Module configuration parameters
 * @return string Success or error message
 */
function authentik_SuspendAccount(array $params) {
    try {
        $baseUrl = $params['configoption1'];
        $token = $params['configoption2'];
        $groupName = $params['configoption3'];
        
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
                'username' => $username,
                'group' => $groupName
            ],
            'Attempting to suspend account',
            null
        );

        // First, get the user ID
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
        curl_close($ch);

        logModuleCall(
            'authentik',
            'GetUser',
            $userUrl,
            $response,
            "HTTP Code: {$httpCode}"
        );

        if ($httpCode !== 200) {
            return 'Failed to find user: ' . $response;
        }

        $users = json_decode($response, true);
        if (empty($users['results'])) {
            return 'User not found';
        }

        $userId = $users['results'][0]['pk'];

        // Get the group ID
        $groupsUrl = $baseUrl . '/api/v3/core/groups/?name=' . urlencode($groupName);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $groupsUrl,
            CURLOPT_RETURNTRANSFER => true,
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
            'GetGroup',
            $groupsUrl,
            $response,
            "HTTP Code: {$httpCode}"
        );

        if ($httpCode !== 200) {
            return "Failed to find group '{$groupName}': " . $response;
        }

        $groups = json_decode($response, true);
        if (empty($groups['results'])) {
            return "Group '{$groupName}' not found";
        }

        $groupId = $groups['results'][0]['pk'];

        // Remove user from group
        $removeFromGroupUrl = $baseUrl . '/api/v3/core/groups/' . $groupId . '/users/' . $userId . '/';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $removeFromGroupUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        logModuleCall(
            'authentik',
            'RemoveUserFromGroup',
            $removeFromGroupUrl,
            $response,
            "HTTP Code: {$httpCode}"
        );

        if ($httpCode === 204 || $httpCode === 200) {
            return 'success';
        }

        return "Failed to remove user from group '{$groupName}': " . $response;
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