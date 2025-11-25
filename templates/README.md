# Payment Report Template

This directory contains the customizable HTML template for the payment report print feature.

## Template File

**File:** `payment_report_template.html`

This is an HTML template file that uses placeholders (in the format `{{PLACEHOLDER_NAME}}`) which are automatically replaced with actual data when generating a report.

## Available Placeholders

You can use the following placeholders in your template:

| Placeholder | Description | Example |
|------------|-------------|---------|
| `{{ORGANIZATION_NAME}}` | Your organization's name from Gibbon settings | "My School" |
| `{{ORGANIZATION_ADDRESS}}` | Your organization's address (HTML formatted) | "123 Main St<br>City, Country" |
| `{{REPORT_TITLE}}` | Title of the report | "Payment Report" |
| `{{GENERATED_DATE}}` | Date and time when report was generated | "2025-11-25 14:30:00" |
| `{{GENERATED_BY}}` | Name of user who generated the report | "John Smith" |
| `{{FROM_DATE}}` | Start date of the report period | "01/01/2025" |
| `{{TO_DATE}}` | End date of the report period | "31/12/2025" |
| `{{TOTAL_PAYMENTS}}` | Total number of payments in the period | "150" |
| `{{TOTAL_AMOUNT}}` | Total sum of all payments | "45,250.00" |
| `{{PAYMENT_TABLE}}` | Complete HTML table with all payment data | (Full table HTML) |
| `{{SEPA_ACCOUNT_INFO}}` | SEPA account info if filtering by account | "SEPA Account: John Doe (DE****123)" |

## How to Customize

### 1. Edit the HTML Template

Simply open `payment_report_template.html` in any text editor and modify:

- **Header Section**: Change the layout, add your logo, modify colors
- **Summary Section**: Rearrange information, change styling
- **Footer**: Add custom footer text, legal disclaimers, etc.
- **CSS Styles**: Modify the `<style>` section to change colors, fonts, spacing

### 2. Example Customizations

**Add a Logo:**
```html
<div class="header">
    <img src="path/to/logo.png" alt="Logo" style="max-width: 200px;">
    <h1>{{ORGANIZATION_NAME}}</h1>
    ...
</div>
```

**Change Colors:**
```css
.summary {
    background-color: #your-color;  /* Change background */
    border-left: 4px solid #your-color;  /* Change border */
}
```

**Add Custom Footer Text:**
```html
<div class="footer">
    <p>For questions, contact: finance@yourschool.com</p>
    <p>Generated on {{GENERATED_DATE}}</p>
</div>
```

### 3. Important Notes

- **DO NOT** remove the placeholders you want to use (e.g., keep `{{ORGANIZATION_NAME}}` if you want it)
- **HTML is allowed** in the template - you can use any valid HTML/CSS
- **Security**: User data is automatically sanitized for security (XSS protection)
- **Special placeholders** like `{{PAYMENT_TABLE}}` contain pre-formatted HTML - don't escape them

## Template Structure

```
payment_report_template.html
├── <head> - Metadata and CSS styles
├── <body>
│   ├── Print buttons (hidden when printing)
│   ├── Header (organization name, address, title)
│   ├── Report info (generated date, user, SEPA account)
│   ├── Summary (dates, totals)
│   ├── Payment table
│   └── Footer
```

## Testing Your Changes

1. Edit the template file
2. Save your changes
3. Go to: **Sepa → Reports → Payment Report by Date Range**
4. Select dates and click "Generate Report"
5. Click "Print Report" to see your customized template

## Backup

**Always keep a backup** of the original template before making major changes!

```bash
cp payment_report_template.html payment_report_template.backup.html
```

## Support

If you need help customizing the template, refer to:
- HTML/CSS documentation: https://developer.mozilla.org/
- Gibbon documentation: https://docs.gibbonedu.org/
