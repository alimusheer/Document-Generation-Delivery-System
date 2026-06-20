# PDF Automation & Email Delivery

## Overview

PDF Automation & Email Delivery is a PHP-based document automation workflow that generates branded PDF documents from user-provided data and automatically delivers them via email.

The project demonstrates a complete backend workflow including form processing, PDF generation, email automation, temporary file management, and third-party library integration.

While the current implementation uses a fitness plan template as a demonstration, the workflow can be adapted to various business scenarios such as invoices, quotations, certificates, reports, contracts, onboarding documents, and other personalized PDF-based processes.

---

## Features

* Dynamic PDF generation using mPDF
* Automated email delivery using PHPMailer
* Form-based document creation workflow
* Branded PDF template generation
* SMTP email integration
* Temporary file cleanup after delivery
* Composer-based dependency management
* Responsive web interface
* Reusable document automation architecture

---

## Technology Stack

### Backend

* PHP 8.3+
* PHPMailer
* mPDF
* Composer

### Infrastructure

* SMTP Email Server
* Apache / Nginx Web Server

---

## Requirements

* PHP 8.3 or newer
* Composer
* SMTP account for email delivery
* Web server with PHP support

---

## Installation

### 1. Clone Repository

```bash
git clone https://github.com/Ali-Hassan-Mushir/Document-Generation-Delivery-System.git
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Email Settings

Update SMTP configuration in the application according to your email provider.

### 4. Verify Directory Permissions

Ensure temporary directories are writable by PHP.

### 5. Launch Application

Access the application through your web server.

---

## Workflow

1. User submits document data through the web form.
2. Application processes the submitted information.
3. PDF document is generated dynamically.
4. Generated PDF is attached to an email.
5. Email is delivered through SMTP.
6. Temporary PDF files are removed automatically.

---

## Example Use Cases

* Fitness Plans
* Invoices
* Quotations
* Certificates
* Business Reports
* Contracts
* Client Documentation
* Educational Materials

---

## Current Status

### Completed

* Form Processing
* PDF Generation
* Email Delivery
* SMTP Integration
* Temporary File Cleanup
* Dependency Management
* Repository Cleanup and Modernization
* Environment-Based Configuration
* CSRF Protection
* Security Response Headers
* Session Hardening
* Enhanced Input Validation
* Logging System
* Rate Limiting
* SMTP Abuse Protection (daily global and per-IP quotas)
* Cloudflare Turnstile Bot Protection
* Improved Error Handling
* Deployment Security Checklist (maintained privately)

### Planned Improvements

* Transactional email provider integration for production deliverability

---

## Security

This application includes several production-oriented security measures:

* Environment-based configuration via `.env`
* CSRF protection on form submission
* Security response headers (Content-Security-Policy, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy)
* Hardened session cookies (HttpOnly, SameSite, Secure)
* Per-IP rate limiting
* SMTP abuse protection with daily global and per-IP quotas
* Cloudflare Turnstile bot protection on form submission
* Application logging for security-relevant failures

### Composer Security Audit

Date: 2026-06-18

Command:

```bash
composer audit
```

Result:

```
No security vulnerability advisories found.
```

---

## Project Purpose

This project was created to demonstrate practical backend engineering skills through a real-world document automation workflow.

Key concepts demonstrated include:

* PHP Backend Development
* PDF Generation Workflows
* Email Automation
* SMTP Integration
* Composer Dependency Management
* Workflow Automation
* Application Security Improvements
* Production-Oriented Development Practices

---

## License

This project is provided for educational, portfolio, and demonstration purposes.
