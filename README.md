# Authentik WHMCS Integration Module

This module provides seamless integration between WHMCS (Web Host Manager Complete Solution) and Authentik, an open-source Identity Provider. It allows WHMCS to automatically provision, manage, and terminate user accounts in Authentik when hosting services are purchased or cancelled.

## Features

- Automatic user provisioning in Authentik when a service is activated in WHMCS
- Automatic user suspension when a service is suspended in WHMCS
- Automatic user termination when a service is terminated in WHMCS
- Configurable group assignment for new users
- Secure API token-based authentication
- Detailed logging for troubleshooting

## Prerequisites

- A running WHMCS installation
- An Authentik instance with administrative access
- An Authentik API token with appropriate permissions

## Installation

1. Download the module files
2. Upload the contents to your WHMCS installation in the following directory:
   ```
   /modules/servers/authentik/
   ```
3. In WHMCS admin area, go to Setup > Products/Services > Servers
4. Click "Add New Server"
5. Select "Authentik" from the Module dropdown
6. Configure the required settings

## Configuration

The module requires the following configuration options:

- **Authentik URL**: The base URL of your Authentik instance (e.g., https://authentik.example.com)
- **API Token**: Your Authentik API token for authentication
- **Group Name**: The name of the Authentik group where new users should be added

## Usage

Once configured, the module will automatically:

1. Create a new Authentik user when a service is activated
2. Suspend the user's access when a service is suspended
3. Remove the user's access when a service is terminated

The module generates unique usernames based on the client's email address and ensures there are no conflicts with existing users.

## Logging

The module includes comprehensive logging using WHMCS's built-in logging system. All API interactions and important operations are logged for debugging purposes. You can view these logs in:

WHMCS Admin Area > Utilities > Logs > Module Log

## Security Considerations

- API tokens should be kept secure and never shared
- The module uses HTTPS for all API communications
- Passwords are securely handled and never stored in plain text

## Troubleshooting

Common issues and their solutions:

1. **Connection Errors**
   - Verify the Authentik URL is correct and accessible
   - Check that the API token is valid and has the required permissions

2. **User Creation Failures**
   - Ensure the specified group exists in Authentik
   - Verify the API token has permission to create users and modify groups

3. **SSL/TLS Issues**
   - Ensure your WHMCS installation has valid SSL certificates
   - Verify that your server's CA certificates are up to date

## Support

For issues, bug reports, or feature requests, please open an issue in the project's repository.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
