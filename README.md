# EDGAR Company Stats WordPress Plugin

Display comprehensive public company information and financial metrics directly on your WordPress site using the SEC EDGAR API.

## Overview

The EDGAR Company Stats plugin retrieves real-time company data from the U.S. Securities and Exchange Commission's (SEC) EDGAR database and displays it in an organized, user-friendly format. Perfect for financial blogs, investor relations pages, or any site that needs to display company information.

## Features

- **Company Overview**: CIK, ticker symbols, exchanges, entity type, SIC codes, and more
- **Financial Metrics**: Latest available financial data including:
  - Total Assets
  - Total Liabilities
  - Current Assets
  - Current Liabilities
  - Revenue
  - Net Income
  - Operating Cash Flow
  - Diluted Earnings Per Share (EPS)
  - Shares Outstanding
- **Company Addresses**: Business and mailing addresses
- **Recent Filings**: Links to the 10 most recent SEC filings
- **Company Description**: Official company description from SEC filings
- **Responsive Design**: Clean, professional styling that works on all devices

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Active internet connection (plugin fetches data from SEC API)
- WordPress site must be able to make outbound HTTP requests to `data.sec.gov` and `www.sec.gov`

## Installation

1. Upload the `edgar-company-stats` folder to `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. No configuration required - start using the shortcode immediately!

## Usage

### Basic Shortcode

Add the shortcode anywhere in your posts, pages, or widgets:

```
[edgar_stats symbol="IBM"]
```

### Shortcode Attributes

- **`symbol`** (required): The stock ticker symbol for the company (e.g., "AAPL", "MSFT", "GOOGL")
  - Default: "IBM" (if not provided)

### Examples

**Display Apple Inc. information:**
```
[edgar_stats symbol="AAPL"]
```

**Display Microsoft Corporation information:**
```
[edgar_stats symbol="MSFT"]
```

**Display Tesla Inc. information:**
```
[edgar_stats symbol="TSLA"]
```

**Display Amazon.com Inc. information:**
```
[edgar_stats symbol="AMZN"]
```

## Displayed Information

The plugin displays several sections of information:

### Company Overview
- CIK (Central Index Key)
- Primary Tickers
- Exchanges
- Entity Type
- Category
- SIC Code and Description
- State of Incorporation
- Fiscal Year End
- EIN (Employer Identification Number)
- LEI (Legal Entity Identifier)
- Phone
- Website
- Investor Relations Site
- Most Recent Filing Date

### Addresses
- Business Address
- Mailing Address

### Key Financial Metrics
The plugin displays the most recent available value for each metric with:
- Metric name
- Formatted value (currency, number, or decimal)
- Date as of when the metric was reported

### Recent Filings
A table showing the 10 most recent SEC filings with:
- Form type (e.g., 10-K, 10-Q, 8-K)
- Filing Date
- Report Date
- Accession Number
- Link to view the document

### Company Description
Official company description from SEC filings (when available)

## Error Handling

The plugin gracefully handles errors and displays user-friendly messages:

- **Invalid Symbol**: If the ticker symbol cannot be found in the SEC database
- **API Errors**: If the SEC API is unavailable or returns an error
- **Missing Data**: If certain information is not available for a company

Warning messages appear in yellow alert boxes when partial data is available but some requests failed.

## Troubleshooting

### Plugin Not Displaying Data

1. **Check Symbol Validity**: Ensure you're using a valid stock ticker symbol (e.g., "AAPL" not "Apple")
2. **Check Internet Connection**: The plugin requires an active internet connection to fetch data
3. **Check WordPress Debug Log**: Enable `WP_DEBUG` in `wp-config.php` to see detailed error messages
4. **SEC API Rate Limiting**: The SEC may rate limit requests. If you see errors, wait a few minutes and try again

### Common Issues

**"Symbol not found" Error**
- Verify the ticker symbol is correct
- Some companies may not be in the SEC database
- Ensure the symbol is in uppercase (the plugin converts it automatically)

**"Unable to retrieve company information" Error**
- The SEC API may be temporarily unavailable
- Check your server's ability to make outbound HTTP requests
- Verify your server can reach `data.sec.gov` and `www.sec.gov`

**Missing Financial Metrics**
- Some companies may not have all metrics available
- Financial data depends on the company's most recent filings
- Newly public companies may have limited data

**Styling Issues**
- The plugin includes inline styles that should work immediately
- If styles conflict with your theme, you can override them with custom CSS

## Technical Details

### API Endpoints Used

- `https://www.sec.gov/files/company_tickers.json` - Ticker to CIK lookup
- `https://data.sec.gov/api/xbrl/companyfacts/CIK{cik}.json` - Company financial facts
- `https://data.sec.gov/submissions/CIK{cik}.json` - Company profile and filings

### User-Agent Header

The plugin includes a proper User-Agent header as required by the SEC:
```
WordPress Plugin - buildingbettersoftware.io blake@blakehowe.com
```

### Caching

The plugin does not implement caching by default. Each shortcode call makes fresh API requests. For high-traffic sites, consider implementing caching at the WordPress level or using a caching plugin.

## Security

- All output is properly escaped using WordPress functions (`esc_html()`, `esc_url()`)
- User input is sanitized before use
- API requests use WordPress's built-in `wp_remote_get()` function
- No user data is stored or transmitted

## Support

For issues or questions:
1. Check the WordPress debug log for detailed error messages
2. Verify your ticker symbols are correct
3. Ensure your server can make outbound HTTP requests

## License

This plugin is licensed under GPL2.

## Changelog

### Version 1.0
- Initial release
- Basic shortcode functionality
- Company overview display
- Financial metrics display
- Recent filings display
- Error handling and warnings
