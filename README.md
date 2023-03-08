# PHP-IMAP-Client
## REQUIREMENTS
## CONNECTION
## NOOP
The NOOP command always succeeds.  It does nothing. Since any command can return a status update as untagged data, the NOOP command can be used as a periodic poll for new messages or message status updates during a period of inactivity (this is the preferred method to do this). The NOOP command can also be used to reset any inactivity autologout timer on the server.
```php
$imapConnection->noop();
```
Reference: [RFC 3501 section 6.1.2](https://datatracker.ietf.org/doc/html/rfc3501#section-6.1.2)
## authenticate
https://datatracker.ietf.org/doc/html/rfc3501#section-6.2.2
```php
$imapConnection->authenticate();
```
Indicates a SASL authentication mechanism to the server. If the server supports the requested authentication mechanism, it performs an authentication protocol exchange to authenticate and identify the client.
## logout
https://datatracker.ietf.org/doc/html/rfc3501#section-6.1.3
```php
$imapConnection->logout();
```
Informs the server that the client is done with the connection.
## login
https://datatracker.ietf.org/doc/html/rfc3501#section-6.2.3
```php
$imapConnection->login($username, $password);
```
Identifies the client to the server and carries the plaintext password authenticating this user.
### select
### create
### delete
### rename
### subscribe
### unsubscribe
### list
### lsub
### status
### decodeMimeHeader
