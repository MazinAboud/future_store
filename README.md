# Future Store — E-Commerce Platform

A full-featured e-commerce platform built in PHP and MySQL: product catalog, cart, checkout, order management, user accounts, and an admin dashboard with reporting/export — plus a REST-style `/api` layer for programmatic access.

Source code push in progress. This README documents the system as verified from the working local build.

## Features

- Product catalog with cart and checkout flow
- Order management and user account system
- Admin dashboard with reporting and data export
- REST-style `/api` layer
- Environment-aware configuration (dev vs. production behavior, e.g. error suppression)

## Security

- Prepared statements / PDO throughout (no raw string-interpolated queries)
- CSRF protection on state-changing requests
- Hashed passwords (`password_hash` / `password_verify`)
- Production-minded config: error display suppressed in production, environment-aware base path

## Tech Stack

PHP · MySQL · JavaScript

## Status

Actively developed.
# future_store
E-commerce platform (PHP/MySQL) with product catalog, cart, and order management.
