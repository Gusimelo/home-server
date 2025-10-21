### OMIE Day-Ahead Market Price Downloader - User Manual

**Version:** 1.0.0
**Author:** Nacho Tizon
**Date:** 2022-01-12

---

### 1. Introduction

This manual describes the functionality and usage of the `omie.py` Python script. The script is a web scraper designed to download day-ahead electricity market hourly prices for Spain from the official OMIE (Operador del Mercado Ibérico de Energía) website.

It allows users to download data for a single day or a specified range of dates and consolidates the results into a single, clean CSV file.

### 2. Requirements

The script requires Python 3 and the following libraries:

*   `argparse`: For parsing command-line arguments.
*   `requests`: For making HTTP requests to the OMIE website.
*   `certifi`: To provide SSL certificates for secure connections.
*   `re`: For regular expression operations (used to extract filenames).
*   `sys`: For system-level operations, like exiting the script on error.
*   `datetime`: For handling and manipulating dates.

These can typically be installed using pip:
```bash
pip install requests certifi
```

### 3. Functionality & Usage

The script is executed from the command line and accepts one primary argument to specify the download date(s).

#### Command-Line Argument

*   `-d` or `--download`: This argument specifies the date or date range for which to download the data.
    *   **Single Day Download:** Provide one date in `dd/mm/yyyy` format.
    *   **Date Range Download:** Provide two dates in `dd/mm/yyyy` format, representing the start and end of the range. The script will automatically handle if the dates are provided out of order.

#### Examples

*   **To download data for a single day (e.g., January 15, 2022):**
    ```bash
    python omie.py -d 15/01/2022
    ```

*   **To download data for a date range (e.g., from January 1, 2022, to January 7, 2022):**
    ```bash
    python omie.py -d 01/01/2022 07/01/2022
    ```

*   **To view the help message:**
    ```bash
    python omie.py -h
    ```

### 4. Algorithm and Workflow

The script follows a clear, step-by-step process to fetch and process the data.

1.  **Argument Parsing**: The script first checks for the `-d` argument. If no arguments are provided, it prints a help message and exits.

2.  **Date Validation and Processing**:
    *   It takes one or two date strings as input. If only one is provided, it is used as both the start and end date.
    *   It attempts to parse the date strings into `datetime` objects using the `dd/mm/yyyy` format. If this fails, it prints an error and exits.
    *   It ensures the "from" date is earlier than the "to" date, swapping them if necessary.
    *   It calculates the total number of days in the specified range.

3.  **File Download Loop**:
    *   The script iterates through each day in the date range.
    *   For each day, it constructs a specific download URL for the OMIE website. The URL format is:
        `https://www.omie.es/es/file-download?parents%5B0%5D=marginalpdbc&filename=marginalpdbc_YYYYMMDD.1`
        where `YYYYMMDD` is the date of the file to be downloaded.
    *   It initiates an HTTP GET request to this URL.

4.  **Data Extraction and Cleaning**:
    *   It checks if the request was successful (HTTP status code 200).
    *   If successful, it inspects the response headers to confirm a file is being returned.
    *   It reads the raw binary content of the downloaded file.
    *   It cleans the content by removing OMIE-specific header and footer lines (`MARGINALPDBC;\r\n` and `*\r\n`). This ensures that when files are concatenated, the final CSV is well-formed.
    *   The cleaned content from each daily file is appended to a master byte string.

5.  **Output File Generation**:
    *   After looping through all the dates, the script checks if any data was successfully downloaded.
    *   If data exists, it creates a new CSV file. The filename is automatically generated based on the date range, following the pattern: `marginalpdbc_DDMMYYYY_DDMMYYYY.csv`.
    *   It writes the aggregated and cleaned byte string into this single output file.
    *   A success message is printed to the console, indicating the name of the created file.

6.  **Error Handling**:
    *   If the date arguments are invalid, the script prints an error and exits.
    *   If a file for a specific day is not found on the OMIE server or another HTTP error occurs (e.g., 404 Not Found), it prints an error message with the problematic URL and continues to the next day.

This process results in a single, clean CSV file containing all the hourly day-ahead market prices for the requested period, ready for analysis.