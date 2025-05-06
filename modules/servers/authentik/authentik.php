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
function generateSimplePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function authentik_CreateAccount(array $params) {
    try {
        $baseUrl = $params['configoption1'];
        $token = $params['configoption2'];
        $groupName = $params['configoption3'];
        
        // Generate unique username and password
        $username = generateUniqueUsername($params);
        $password = generateSimplePassword(12);

        // Store credentials in WHMCS
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update([
                'username' => $username,
                'password' => encrypt($password)
            ]);

        // Create user in Authentik
        $createUserUrl = rtrim($baseUrl, '/') . '/api/v3/core/users/';
        
        $userData = [
            'username' => $username,
            'email' => $params['clientsdetails']['email'],
            'name' => $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
            'password' => $password,
            'is_active' => true,
            'path' => 'if/flow/initial-setup',  // Removed trailing slash
            'attributes' => [
                'settings' => [
                    'force_password_change' => true,
                    'mfa_required' => true,
                    'mfa_method_preferred' => 'totp'
                ]
            ]
        ];

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

        if ($httpCode !== 201) {
            throw new Exception('Failed to create user: ' . $response);
        }

        // Get the user ID from the response
        $userData = json_decode($response, true);
        if (!isset($userData['pk'])) {
            throw new Exception('Invalid response from Authentik API');
        }

        $userId = $userData['pk'];

        // Explicitly set the password
        $setPasswordUrl = rtrim($baseUrl, '/') . '/api/v3/core/users/' . $userId . '/set_password/';
        
        $passwordData = [
            'password' => $password
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $setPasswordUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($passwordData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]
        ]);

        $setPasswordResponse = curl_exec($ch);
        $setPasswordHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($setPasswordHttpCode !== 200 && $setPasswordHttpCode !== 201 && $setPasswordHttpCode !== 204) {
            throw new Exception('Failed to set password: ' . $setPasswordResponse);
        }

        // After setting password, set user stage to password change
        $setStageUrl = rtrim($baseUrl, '/') . '/api/v3/stages/prompt/stages/';
        
        $stageData = [
            'name' => 'Password Change Stage - ' . $username,
            'flows' => ['initial-setup'],
            'fields' => [
                [
                    'field_key' => 'password',
                    'label' => 'New Password',
                    'type' => 'password',
                    'required' => true,
                    'placeholder' => 'Enter your new password'
                ],
                [
                    'field_key' => 'password_repeat',
                    'label' => 'Confirm New Password',
                    'type' => 'password',
                    'required' => true,
                    'placeholder' => 'Confirm your new password'
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $setStageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($stageData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]
        ]);

        $stageResponse = curl_exec($ch);
        $stageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($stageHttpCode !== 200 && $stageHttpCode !== 201 && $stageHttpCode !== 204) {
            logModuleCall(
                'authentik',
                'SetStage_Error',
                [
                    'error' => 'Failed to set password change stage',
                    'response' => $stageResponse,
                    'http_code' => $stageHttpCode
                ],
                null,
                null
            );
        }

        // Set MFA policy
        $policyUrl = rtrim($baseUrl, '/') . '/api/v3/policies/bindings/';
        
        $policyData = [
            'policy' => [
                'name' => 'MFA Required - ' . $username,
                'execution_logging' => true,
                'component' => 'authentik_stages_authenticator_validate',
                'enabled' => true
            ],
            'target' => $userId,
            'enabled' => true,
            'order' => 0
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $policyUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($policyData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]
        ]);

        $policyResponse = curl_exec($ch);
        $policyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($policyHttpCode !== 200 && $policyHttpCode !== 201 && $policyHttpCode !== 204) {
            logModuleCall(
                'authentik',
                'SetPolicy_Error',
                [
                    'error' => 'Failed to set MFA policy',
                    'response' => $policyResponse,
                    'http_code' => $policyHttpCode
                ],
                null,
                null
            );
        }

        // Get the group ID
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

        // Add user to group
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
                    'service_username' => $username,
                    'service_password' => $password,
                ))),
            );

            $results = localAPI($command, $postData);
            
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

/**
 * Unsuspend a user account in Authentik
 *
 * This function is called when a service is unsuspended in WHMCS.
 * It re-enables the user in Authentik.
 *
 * @param array $params Module configuration parameters
 * @return string Success or error message
 */
function authentik_UnsuspendAccount(array $params) {
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

        // Find the user ID
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
        
        if (!isset($userData['results']) || empty($userData['results'])) {
            throw new Exception('Failed to find user: ' . $username);
        }

        $userId = $userData['results'][0]['pk'];

        // Re-enable the user
        $updateUrl = rtrim($baseUrl, '/') . '/api/v3/core/users/' . $userId . '/';
        
        $updateData = [
            'is_active' => true
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

        if ($httpCode === 200) {
            return 'success';
        }

        throw new Exception("Failed to re-enable user. HTTP Code: " . $httpCode . ($response ? ". Response: " . $response : ""));

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
 * Client Area Output
 *
 * @param array $params
 * @return array
 */
function authentik_ClientArea($params) {
    $serviceId = $params['serviceid'];
    
    // Get service details from database
    $service = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->first();
    
    // Get Authentik instance URL
    $authentikUrl = $params['configoption1'];
    
    return [
        'tabOverviewReplacementTemplate' => 'templates/overview.tpl',
        'templateVariables' => [
            'username' => $service->username,
            'password' => decrypt($service->password),
            'authentik_url' => $authentikUrl
        ]
    ];
}

// End of file