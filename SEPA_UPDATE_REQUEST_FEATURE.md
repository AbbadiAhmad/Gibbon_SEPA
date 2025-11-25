# SEPA Update Request Feature

## Overview

Version 2.1.0 introduces a secure parent self-service feature that allows parents to submit SEPA (bank account) information update requests through the Gibbon portal. All updates require administrative approval before being applied to the system.

**Version 2.1.1** adds advanced security features including cryptographic integrity verification and comprehensive user metadata tracking to create a tamper-proof, legally-defensible audit trail.

## Features

### For Parents
- **View Current SEPA Information**: Parents can see their family's current masked IBAN and account holder information
- **Submit Update Requests**: Parents can request changes to their SEPA details including:
  - Account holder name (payer)
  - IBAN
  - BIC/SWIFT code
  - SEPA mandate signed date
  - Optional notes
  - Custom fields (if configured)
- **Track Request Status**: Parents can view the history of their update requests and their approval status
- **Pending Request Protection**: Only one pending request is allowed at a time

### For Administrators
- **Review Pending Requests**: View all pending SEPA update requests from parents
- **Side-by-Side Comparison**: See current values vs. requested new values with highlighted changes
- **Approve or Reject**: Make informed decisions with optional notes
- **Automatic Processing**: Upon approval, data is automatically copied to gibbonSEPA with:
  - IBAN properly masked (XX****XXX format)
  - BIC set to NULL (never stored per security requirements)
  - Audit trail maintained

## Security Features

### Data Encryption (v2.1.0)
- All sensitive SEPA data in update requests is encrypted at rest using **AES-256-GCM**
- Encrypted fields include:
  - Account holder names (old and new)
  - IBANs (old and new)
  - BIC codes (old and new)
- Data is automatically encrypted when stored and decrypted when retrieved

### Cryptographic Integrity Verification (v2.1.1)
- **SHA-256 hash** generated for every update request
- Hash computed from encrypted data to detect any tampering at database level
- Automatic verification on data retrieval with error logging
- Visual alerts for administrators if tampering detected
- **Tamper-proof**: Any modification to encrypted fields invalidates the hash

### User Metadata Tracking (v2.1.1)
Comprehensive capture of user context for proof of action:

**Submitter Metadata:**
- IP address (IPv4/IPv6)
- Browser/User agent
- Timezone
- Language preferences
- HTTPS connection status
- Browser fingerprint
- Session information
- Request timestamp with timezone

**Approver Metadata:**
- IP address of administrator
- Browser/User agent
- Timezone
- Language preferences
- Decision timestamp
- All metadata captured independently for approval/rejection

### IBAN Masking
- When approved, IBANs are masked before being stored in the main gibbonSEPA table
- Masking format: `XX****XXX` (first 2 characters + **** + last 3 characters)
- Full IBAN is visible to admins during the approval process but masked after approval

### Complete Audit Trail
All update requests maintain a legally-defensible audit trail:
- **Submission tracking**: Who submitted, when, from where (IP), with what device
- **Approval tracking**: Who approved/rejected, when, from where, with what device
- **Historical data**: Both old and new values preserved (encrypted)
- **Status tracking**: pending, approved, rejected
- **Notes**: Optional notes from both parents and admins
- **Integrity proof**: Cryptographic hash to prove no tampering
- **Non-repudiation**: Complete metadata makes it difficult to deny actions

## Database Schema

### New Table: gibbonSEPAUpdateRequest

```sql
CREATE TABLE `gibbonSEPAUpdateRequest` (
    `gibbonSEPAUpdateRequestID` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `gibbonFamilyID` int(7) unsigned zerofill NOT NULL,
    `gibbonSEPAID` int(8) unsigned zerofill DEFAULT NULL,

    -- Old values (encrypted)
    `old_payer` varchar(500) DEFAULT NULL,
    `old_IBAN` varchar(500) DEFAULT NULL,
    `old_BIC` varchar(500) DEFAULT NULL,
    `old_SEPA_signedDate` date DEFAULT NULL,
    `old_note` text DEFAULT NULL,
    `old_customData` text DEFAULT NULL,

    -- New values (encrypted)
    `new_payer` varchar(500) NOT NULL,
    `new_IBAN` varchar(500) NOT NULL,
    `new_BIC` varchar(500) DEFAULT NULL,
    `new_SEPA_signedDate` date DEFAULT NULL,
    `new_note` text DEFAULT NULL,
    `new_customData` text DEFAULT NULL,

    -- Workflow
    `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',

    -- Audit trail
    `gibbonPersonIDSubmitted` int(10) unsigned zerofill NOT NULL,
    `submittedDate` datetime NOT NULL,
    `gibbonPersonIDApproved` int(10) unsigned zerofill DEFAULT NULL,
    `approvedDate` datetime DEFAULT NULL,
    `approvalNote` text DEFAULT NULL,

    -- Metadata
    `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `timestampUpdated` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`gibbonSEPAUpdateRequestID`),
    KEY `gibbonFamilyID` (`gibbonFamilyID`),
    KEY `gibbonSEPAID` (`gibbonSEPAID`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Installation & Setup

### 1. Upgrade Module
1. Navigate to **System Admin > Manage Modules**
2. Find the SEPA module
3. Click **Update** to run database migrations (this will create the new table)

### 2. Configure Encryption Key (IMPORTANT!)

For production security, you must set up a dedicated encryption key:

**Option A: Environment Variable (Recommended)**
```bash
# Add to your server environment or .env file
export SEPA_ENCRYPTION_KEY="your-base64-encoded-32-byte-key"
```

**Option B: Generate a Key**
```php
// Run this once to generate a secure key
php -r "echo base64_encode(random_bytes(32));"
```

Save the output and set it as the `SEPA_ENCRYPTION_KEY` environment variable.

**Important**:
- Keep this key secure and backed up
- If you lose this key, encrypted data cannot be recovered
- Use the same key across all web servers in load-balanced setups
- Never commit this key to version control

### 3. Set Permissions

**For Parents:**
1. Navigate to **System Admin > Manage Permissions**
2. Find the **Sepa** module
3. Enable "Update SEPA Information" for the **Parent** role

**For Administrators:**
- The "Approve SEPA Updates" action is enabled for **Admin** role by default

### 4. Test the Workflow

1. **As a Parent:**
   - Log in with a parent account
   - Navigate to **Sepa > Update SEPA Information**
   - Submit a test update request

2. **As an Administrator:**
   - Navigate to **Sepa > Approve SEPA Updates**
   - Review the pending request
   - Approve or reject with a note

3. **Verify:**
   - Check that approved changes appear in **Sepa > View Family SEPA**
   - Verify IBAN is properly masked
   - Check the audit trail in update history

## Usage

### Parent Workflow

1. Navigate to **Sepa > Update SEPA Information**
2. Review current SEPA information (displayed with masked IBAN)
3. View update request history and status
4. Fill in the update request form with new bank details
5. Submit the request
6. Wait for administrative approval
7. Receive notification when request is processed

### Administrator Workflow

1. Navigate to **Sepa > Approve SEPA Updates**
2. View list of all pending update requests
3. Click **Review** on any request to see details
4. Review the side-by-side comparison of old vs. new values
5. Choose to **Approve** or **Reject** the request
6. Optionally add a note explaining the decision
7. Submit the decision

Upon approval:
- New data is automatically copied to gibbonSEPA
- IBAN is masked
- BIC is set to NULL
- Update request status is changed to "approved"
- Audit trail is updated

## File Structure

### New Files Created

```
Gibbon_SEPA/
├── CHANGEDB.php                           # Updated with v2.1.0 and v2.1.1 migrations
├── manifest.php                           # Updated with new actions (v2.1.1)
├── src/Domain/
│   ├── SepaEncryption.php                # NEW: Encryption utility class (v2.1.0)
│   ├── SepaUpdateRequestGateway.php      # NEW: Data access with hash verification (v2.1.1)
│   └── UserMetadataCollector.php         # NEW: User metadata collection (v2.1.1)
├── sepa_update_request.php               # NEW: Parent update form (v2.1.0)
├── sepa_update_request_process.php       # NEW: Parent form processor with metadata (v2.1.1)
├── sepa_update_approve.php               # NEW: Admin approval page with integrity check (v2.1.1)
├── sepa_update_approve_process.php       # NEW: Admin approval processor with metadata (v2.1.1)
└── SEPA_UPDATE_REQUEST_FEATURE.md        # This documentation
```

## Technical Details

### Encryption Implementation
- **Algorithm**: AES-256-GCM (Galois/Counter Mode)
- **Initialization Vector**: Random, generated per encryption
- **Authentication Tag**: 16 bytes for integrity verification
- **Key Derivation**: SHA-256 if raw key not available
- **Encoding**: Base64 for storage

### Hash Generation (v2.1.1)
- **Algorithm**: SHA-256
- **Input**: Concatenation of critical fields (encrypted values, IDs, dates, status)
- **Fields Hashed**: gibbonFamilyID, gibbonSEPAID, old/new encrypted values, submitter ID, submitted date, status
- **Output**: 64-character hex string
- **Purpose**: Detect any modification to critical data
- **Verification**: Automatic on retrieval with logging

### Data Flow

```
Parent Submission (v2.1.1):
1. Parent fills form with plain text data
2. Data validated and sanitized
3. UserMetadataCollector captures IP, user agent, timezone, etc.
4. SepaUpdateRequestGateway encrypts sensitive fields
5. SHA-256 hash generated from encrypted data
6. Encrypted data + hash + metadata stored in gibbonSEPAUpdateRequest
7. Status set to 'pending'

Admin Approval (v2.1.1):
1. Admin views request
2. Gateway decrypts data for display
3. Integrity verification: compute hash and compare with stored hash
4. If hash mismatch: Display WARNING alert, recommend not approving
5. If hash valid: Display success confirmation
6. Display submitter metadata (IP, device, location context)
7. Admin makes decision (approve/reject)
8. UserMetadataCollector captures approver IP, user agent, etc.
9. If approved:
   - Decrypt new values
   - Mask IBAN using SepaGateway::maskIBAN()
   - Set BIC to NULL
   - Insert/update gibbonSEPA
   - Update request with status='approved' + approver metadata
10. If rejected:
    - Update request with status='rejected' + approver metadata
    - No changes to gibbonSEPA
```

## Best Practices

1. **Encryption Key Management**
   - Store key securely outside web root
   - Use environment variables, not config files
   - Rotate keys periodically (requires re-encryption)
   - Back up keys in secure key management system

2. **Access Control**
   - Limit "Approve SEPA Updates" to trusted administrators
   - Review parent access periodically
   - Monitor approval logs for unusual activity

3. **Data Retention**
   - Keep approved/rejected requests for audit purposes
   - Consider archiving old requests after a retention period
   - Maintain backups of encrypted data with encryption keys

4. **Security Monitoring**
   - Monitor failed decryption attempts (check error logs)
   - Track approval/rejection ratios
   - Alert on bulk approvals from single admin

## Troubleshooting

### Common Issues

**Issue**: "Encryption key not set" warning in error logs
- **Solution**: Set the `SEPA_ENCRYPTION_KEY` environment variable

**Issue**: Cannot decrypt old data after key change
- **Solution**: Keep the old key available or re-submit requests with new key

**Issue**: Parent cannot submit request
- **Solution**: Check that parent role has permission for "Update SEPA Information"

**Issue**: IBAN not masked after approval
- **Solution**: Check SepaGateway::maskIBAN() is working correctly

**Issue**: BIC appearing in gibbonSEPA table
- **Solution**: Verify approval process is setting BIC to NULL

## Future Enhancements

Potential improvements for future versions:
- Email notifications to parents when requests are approved/rejected
- Bulk approval interface for administrators
- Key rotation utility with automatic re-encryption
- Integration with two-factor authentication
- Document upload for SEPA mandates
- Automated IBAN validation using IBAN registry

## Support

For issues or questions:
1. Check the Gibbon SEPA module documentation
2. Review error logs in Gibbon's error logging system
3. Contact your Gibbon system administrator
4. Report bugs to the module maintainer

## Version History

- **v2.1.1** (2025-01-XX)
  - Added SHA-256 cryptographic hash for tamper detection
  - Comprehensive user metadata collection (IP, user agent, timezone, fingerprint)
  - Automatic integrity verification on data retrieval
  - Visual tamper alerts for administrators
  - Enhanced audit trail with proof of action
  - Database migration to add metadata and hash fields

- **v2.1.0** (2025-01-XX)
  - Initial release of SEPA update request feature
  - AES-256-GCM encryption implementation
  - Parent self-service portal
  - Admin approval workflow
  - Basic audit trail
