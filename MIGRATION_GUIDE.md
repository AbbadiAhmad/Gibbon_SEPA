# SEPA Bank Details Security Migration Guide

## Overview

This guide explains how to migrate your existing SEPA bank details to the new secure masked storage system.

### What Changed?

**Before:**
- Full IBAN and BIC stored in database (e.g., `DE89370400440532013000`)
- Visible to anyone with database access
- Security risk if database is compromised

**After:**
- Only masked IBAN stored (e.g., `DE****000`)
- Format: First 2 characters + `****` + Last 3 characters
- BIC set to NULL (not stored)
- Full bank details stored externally by you

## Security Benefits

✅ Database breach does NOT expose full bank details
✅ Reduced PCI-DSS/GDPR compliance scope
✅ You control where full data is stored
✅ Minimal changes to workflow

## Migration Process

### Step 1: Backup Your Database

**CRITICAL:** Always backup before migration!

```bash
mysqldump -u username -p database_name > sepa_backup_$(date +%Y%m%d).sql
```

### Step 2: Run Migration Script

1. Access the migration script:
   ```
   https://your-gibbon-site.com/modules/Sepa/migrate_mask_bank_details.php
   ```

2. **Preview Mode** (default):
   - Shows current vs. masked data
   - Creates export file with full IBAN/BIC
   - Review carefully before proceeding

3. **Download Export File**:
   - File location: `modules/Sepa/exports/sepa_bank_details_full_backup_YYYY-MM-DD_HHMMSS.xlsx`
   - Contains: Full IBAN, BIC, Family ID, Payer name
   - **IMPORTANT:** Store this file securely OUTSIDE the web application

4. **Execute Migration**:
   - Click "Execute Migration" button
   - Database will be updated permanently
   - All IBANs masked, all BICs set to NULL

### Step 3: Verify Results

1. Check SEPA records in the module
2. Verify IBANs show as `XX****XXX` format
3. Verify BIC shows as NULL or empty
4. Test export functionality

### Step 4: Cleanup

For security, delete the migration script after successful migration:

```bash
rm /path/to/Gibbon_SEPA/migrate_mask_bank_details.php
rm -rf /path/to/Gibbon_SEPA/exports/
```

## How to Use After Migration

### Adding New SEPA Records

**Manual Entry:**
- Admin can enter full IBAN in the form
- System automatically masks before saving
- Warning message shows masking will occur

**Excel Import:**
- Upload Excel with full IBANs
- System masks during import
- Warning displayed before import

### Exporting Data

**Normal Export:**
- Exports masked IBANs only (`DE****000`)
- You match with your external full data using Family ID
- Generate actual SEPA files using your external system

**Matching Process:**
1. Export from Gibbon SEPA (contains Family ID, masked IBAN)
2. Match with your external file using `gibbonFamilyID`
3. Replace masked IBAN with full IBAN from your secure storage
4. Generate SEPA XML or process payments

## External Storage Recommendations

### Option 1: Encrypted File Storage
- Store export file in encrypted folder
- Use VeraCrypt, BitLocker, or similar
- Access only when needed for SEPA generation

### Option 2: Separate Database
- Dedicated secure database server
- Not accessible from web
- Access via VPN or secure connection only

### Option 3: Password Manager (Small Scale)
- Tools like 1Password, Bitwarden (Business)
- Store as secure notes with Family ID
- Good for small schools (<100 families)

## Troubleshooting

### Issue: Export file not created
**Solution:** Check permissions on `modules/Sepa/exports/` directory
```bash
mkdir -p modules/Sepa/exports
chmod 755 modules/Sepa/exports
```

### Issue: Migration fails partway through
**Solution:** Restore from backup and re-run
```bash
mysql -u username -p database_name < sepa_backup_20250124.sql
```

### Issue: Need to re-run migration
**Solution:** Restore backup first, then run script again
- Migration is NOT idempotent
- Running twice will mask already-masked data (breaks format)

## Technical Details

### Masking Function

```php
public function maskIBAN($iban) {
    // Remove spaces, convert to uppercase
    $iban = strtoupper(str_replace(' ', '', $iban));

    // Return NULL if too short (< 5 chars)
    if (strlen($iban) < 5) {
        return null;
    }

    // Format: XX****XXX
    return substr($iban, 0, 2) . '****' . substr($iban, -3);
}
```

### Database Changes

**Tables Updated:**
- `gibbonSEPA` (main SEPA records)
- `gibbonSEPAPaymentEntry` (payment transactions)

**Columns Affected:**
- `IBAN` - Masked
- `BIC` - Set to NULL

### Files Modified

1. `src/Domain/SepaGateway.php` - Added masking methods
2. `sepa_family_addProcess.php` - Mask on insert
3. `sepa_family_editProcess.php` - Mask on update
4. `import_sepa_data.php` - Mask on Excel import

## Compliance Notes

### GDPR
- Bank details = Personal Data (Article 4)
- Masking = "pseudonymization" (Article 4.5)
- Reduces risk in case of breach (Article 32)
- Still need lawful basis for processing

### PCI-DSS
- IBAN/BIC not covered by PCI-DSS (applies to payment cards)
- However, masking follows PCI-DSS best practices
- Reduces scope of security audits

## Support

For issues or questions:
1. Check this guide first
2. Review migration script comments
3. Contact your system administrator

---

**Version:** 1.0
**Last Updated:** 2025-01-24
**Module:** Gibbon SEPA
