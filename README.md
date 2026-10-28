# Family Owed Payment Module for GibbonEdu

This module provides tools to track and manage families payments balance within **GibbonEdu**.  

It allows importing payment data, applying adjustments, calculating fees based on actual student enrollment, and generating clear balance summaries for each family.

---

## Key Features

- **SEPA Payment Import**
  - Import excel-file extraction of bank transactions to record payments efficiently.

- **Manual Payment Entry**
  - Add individual payment entries.

- **Adjustments**
  - Apply positive or negative balance adjustments for corrections if enrollment dates is not exact, discounts, or additional charges.

- **Enrollment-Based Fee Calculation**
  - Fees are automatically calculated based on the student enrollemnt in courses. Calculated monthly bases till the end of the academic year or to the month of deenrollment. 

- **Accurate Family Balance**
  - The system maintains a clear running balance using:
    ```
    Family Balance = Total Payments +/− Adjustments − Total Fees
    ```

- **Transparent Reporting**
  - View family balances, history of payments, linked children, and enrollment contribution to fees.

- **Family Self-Service View**
  - Families can review their own balances and payment history if permissions are granted.

---

## IBAN encryption

IBANs (SEPA records and payment entries) are stored encrypted (AES-256-GCM) and shown in clear text to users with access. The key is **not** stored in the database:

1. Generate a key: `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`
2. Copy `.env.example` to `.env` in the module folder (or the Gibbon root folder) and set `SEPA_IBAN_ENCRYPTION_KEY=<key>`. Alternatively set it as a real environment variable of the web server / PHP-FPM, which is the safest option. `.env` is git-ignored and the module ships an `.htaccess` that denies web access to it (for nginx, add a rule denying `.env`).
3. Back the key up separately from the database backups. Without it the stored IBANs can not be decrypted.

Values written before the encryption was introduced (plain or masked `DE****000` values) are shown as they are and are encrypted the next time the record is saved. Because of the encryption the IBAN can no longer be searched or sorted in the lists.

## Import from registration forms

*Import Family SEPA > Import from registration forms* reads the SEPA data from the registration (admissions) forms of an academic year: choose the forms, map the (configurable) form fields to the SEPA fields, review and edit every row, then import the selected rows. The signature date defaults to the creation date of the form and the note records the form ID. Missing cadence defaults to monthly and the missing withdrawal day to the 5th.

## Installation

1. Download or clone this module into your Gibbon modules directory.
2. Enable it from **System Admin → Manage Modules**.
3. Assign appropriate roles and permissions for finance administrators.
4. Setup or import family payment informaiton.
5. Import payment entries (bank statement, payments list) as excel where the first row is the column headers.

---

## Open Source & Community

This module is **open source and free to use**.  
It is designed to support schools in providing transparent and fair fee accounting.

[License: GPL v3](https://www.gnu.org/licenses/gpl-3.0)

---

## Support & Donations

If this module has been helpful, we kindly welcome donations to support ongoing maintenance and improvements.

**Donate via PayPal:**  
👉 *[Donate here](https://www.paypal.com/donate?hosted_button_id=LZKTGKBK3B444)*

Your support helps keep this module alive and evolving — thank you 💛

---

## Contributing

We welcome:
- Bug reports  
- Feature suggestions  
- Pull requests  

---

## Acknowledgements

Thank you to the **GibbonEdu** community for enabling open, accessible, and learner-focused educational tools.

[GibbonEdu.org website](https://gibbonedu.org/) 

[GibbonEdu github](https://github.com/GibbonEdu/core)

---
