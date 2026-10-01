# Ingress: LAN-Enabled Web & Mobile Event Ticketing System

**Manuel S. Enverga University Foundation (MSEUF)**  
*Event Ticketing, Admission, and Attendance Management System*

Ingress is a high-reliability, LAN-operable event platform designed for campus-wide events at MSEUF. It combines a Laravel 12 web management console for organizers with an Expo React Native mobile application for student discovery, digital ticketing, and multi-gate admission scanning.

---

## System Architecture Overview

```
                                  +---------------------------+
                                  |    Ingress Local Server   |
                                  | (Laptop on Campus Wi-Fi)  |
                                  |                           |
                                  |  +---------------------+  |
                                  |  | Laravel 12 Monolith |  |
                                  |  | - Blade Admin Web   |  |
                                  |  | - Sanctum REST API  |  |
                                  |  +----------+----------+  |
                                  |             |             |
                                  |  +----------v----------+  |
                                  |  |    PostgreSQL 18    |  |
                                  |  |  Pessimistic Locks  |  |
                                  |  |  Atomic Admissions  |  |
                                  |  +---------------------+  |
                                  +-------------+-------------+
                                                |
                                        Campus Wi-Fi / LAN
                                       (No Internet Needed)
                                                |
                   +----------------------------+----------------------------+
                   |                                                         |
         +---------v---------+                                     +---------v---------+
         |   Student Mobile  |                                     |    Staff Mobile   |
         | Expo React Native |                                     | Expo React Native |
         |                   |                                     |                   |
         | - Discover Events |                                     | - Select Gate     |
         | - Register (Safe) |                                     | - Scan QR Ticket  |
         | - View Opaque QR  |                                     | - Anti-Passback   |
         +-------------------+                                     +-------------------+
```

---

## Directory Structure

```
ingress/
├── api/          # Laravel 12 Web Application & REST API
├── mobile/       # Expo React Native Mobile Application
├── docs/         # System Architecture & Operational Guides
│   ├── architecture.md
│   ├── api.md
│   ├── lan-demo.md
│   └── design-decisions.md
└── README.md
```

---

## Quick Start Guide

### 1. Database Setup (PostgreSQL 18)
PostgreSQL runs on port `5433` (or default `5432`) with database `ingress`.
```bash
# Verify connection
psql -h 127.0.0.1 -p 5433 -U postgres -d ingress -c "SELECT 1;"
```

### 2. Backend API & Web Server (Laravel 12)
```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
```

### 3. Mobile Application (Expo SDK 52/53)
```bash
cd mobile
npm install
npx expo start
```
Configure server URL in the mobile app settings to point to your laptop LAN IP (e.g. `http://192.168.55.128:8000/api/v1`).
