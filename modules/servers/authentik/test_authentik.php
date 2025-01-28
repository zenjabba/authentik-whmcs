<?php

require_once __DIR__ . '/modules/servers/authentik/helpers.php';

class AuthentikTester {
    private $config;
    private $verbose;

    public function __construct($configFile, $verbose = false) {
        if (!file_exists($configFile)) {
            throw new Exception("Config file not found: $configFile");
        }
        $this->config = json_decode(file_get_contents($configFile), true);
        $this->verbose = $verbose;

        if (!isset($this->config['authentik_url']) || !isset($this->config['api_token'])) {
            throw new Exception("Config file must contain 'authentik_url' and 'api_token'");
        }
    }

    public function log($message, $data = null) {
        if ($this->verbose) {
            echo date('[Y-m-d H:i:s] ') . $message;
            if ($data !== null) {
                echo ': ' . print_r($data, true);
            }
            echo PHP_EOL;
        }
    }

    public function testGenerateUsername() {
        $this->log("Generating random username");
        $username = generateUsername();
        $this->log("Generated username", $username);
        return $username;
    }

    public function testUsernameCheck($username) {
        $this->log("Checking if username exists", $username);
        
        $params = [
            'configoption1' => $this->config['authentik_url'],
            'configoption2' => $this->config['api_token']
        ];

        $exists = !checkUsernameInAuthentik($username, $params);
        $this->log("Username exists", $exists ? "Yes" : "No");
        return $exists;
    }

    public function testGenerateUniqueUsername() {
        $this->log("Generating unique username");
        
        $params = [
            'configoption1' => $this->config['authentik_url'],
            'configoption2' => $this->config['api_token']
        ];

        $username = generateUniqueUsername($params);
        $this->log("Generated unique username", $username);
        return $username;
    }

    public function runAllTests($count = 1) {
        echo "Running tests...\n\n";

        for ($i = 0; $i < $count; $i++) {
            echo "Test iteration " . ($i + 1) . ":\n";
            echo "----------------------------------------\n";

            try {
                // Test 1: Generate random username
                $randomUsername = $this->testGenerateUsername();
                echo "Random Username: $randomUsername\n";

                // Test 2: Check if username exists
                $exists = $this->testUsernameCheck($randomUsername);
                echo "Username Exists: " . ($exists ? "Yes" : "No") . "\n";

                // Test 3: Generate unique username
                $uniqueUsername = $this->testGenerateUniqueUsername();
                echo "Unique Username: $uniqueUsername\n";

                echo "----------------------------------------\n\n";
            } catch (Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
                echo "----------------------------------------\n\n";
            }
        }
    }
}

// Create a config.json file with your Authentik settings
$configExample = [
    "authentik_url" => "https://your-authentik-instance.com",
    "api_token" => "your-api-token"
];

// Show help if no arguments provided
if ($argc < 2) {
    echo "Usage: php test_authentik.php <command> [options]\n";
    echo "Commands:\n";
    echo "  generate              Generate a single random username\n";
    echo "  check <username>      Check if a username exists\n";
    echo "  unique               Generate a unique username\n";
    echo "  test [count]         Run all tests (optional: specify number of iterations)\n";
    echo "\nOptions:\n";
    echo "  -v, --verbose        Show detailed logging\n";
    echo "\nExample:\n";
    echo "  php test_authentik.php test 5 -v\n";
    exit(1);
}

try {
    // Check if config file exists
    if (!file_exists(__DIR__ . '/config.json')) {
        file_put_contents(__DIR__ . '/config.json', json_encode($configExample, JSON_PRETTY_PRINT));
        echo "Config file created. Please edit config.json with your Authentik settings.\n";
        exit(1);
    }

    // Parse command line arguments
    $verbose = in_array('-v', $argv) || in_array('--verbose', $argv);
    $command = $argv[1];

    $tester = new AuthentikTester(__DIR__ . '/config.json', $verbose);

    switch ($command) {
        case 'generate':
            $username = $tester->testGenerateUsername();
            echo "Generated username: $username\n";
            break;

        case 'check':
            if (!isset($argv[2])) {
                echo "Error: Username required for check command\n";
                exit(1);
            }
            $exists = $tester->testUsernameCheck($argv[2]);
            echo "Username exists: " . ($exists ? "Yes" : "No") . "\n";
            break;

        case 'unique':
            $username = $tester->testGenerateUniqueUsername();
            echo "Generated unique username: $username\n";
            break;

        case 'test':
            $count = isset($argv[2]) && is_numeric($argv[2]) ? (int)$argv[2] : 1;
            $tester->runAllTests($count);
            break;

        default:
            echo "Unknown command: $command\n";
            exit(1);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 