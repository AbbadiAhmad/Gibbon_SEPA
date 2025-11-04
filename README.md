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
