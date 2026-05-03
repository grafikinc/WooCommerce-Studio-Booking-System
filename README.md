# Studio Booking System

A WordPress/WooCommerce plugin for managing recording studio bookings with Google Calendar integration, automatic time slot management, and Purchase Order generation.

Built by [GrafikInc](https://grafikinc.com).

## Features

**Booking Flow**
- Two booking modes: Studio (rooms, podcasts, post-production) and Live Sound & Rentals (backline, equipment, lighting)
- Date picker with available time slots pulled from Google Calendar in real-time
- Duration selector for hourly-rate services
- Engineer preference selection (included with studio bookings, hourly add-on for live events)
- Review step with deposit breakdown and payment instructions before adding to cart
- WooCommerce cart and checkout integration

**Google Calendar Sync**
- Two-way sync: bookings create calendar events, staff-blocked times show as unavailable on the website
- Each room/studio maps to its own Google Calendar
- Pending events (yellow) created when added to cart with a configurable hold period
- Confirmed events (green) with customer details after payment
- Automatic cleanup of expired pending events via hourly cron
- OAuth 2.0 authentication — connect once from the admin panel

**Purchase Orders**
- Auto-generated PO with unique number (PO-1001, PO-1002, etc.) on order confirmation
- Professional printable/PDF-ready page with logo, customer details, booking info, deposit breakdown, and payment instructions
- "Print / Download PO" button on the WooCommerce thank you page
- Designed for artist managers sending to labels for payment approval

**Automatic Discounts**
- Multi-room: 10% off when booking 2+ different rooms
- Full day: 15% off for 8+ hours on the same date
- Engineer bundle: 5% off engineer fee when booked with a room
- All discounts stack, with savings tips shown on the cart page

**Admin Tools**
- Settings page: deposit percentage, payment instructions (M-PESA, PayPal, bank — flexible), studio address, lead time, max advance booking
- Fix Products: one-click tool to set all products to virtual, in-stock, and purchasable
- Debug page with live log viewer and health check
- Bookings list with status tracking

## Use Cases

Built for recording studios, but works for any business that books time slots for a space or service with staff.

**Creative Spaces** — photography studios, rehearsal rooms, dance studios, coworking meeting rooms, podcast studios

**Events & Hospitality** — venue hire, wedding venues, conference rooms, rooftop spaces, private dining rooms

**Wellness & Fitness** — personal training sessions, yoga studio room hire, spa treatment rooms, physiotherapy clinics

**Automotive** — mechanic bays, detailing bays, paint booths. Book a bay + optional technician

**Education** — tutoring sessions, music lessons, driving lessons, language schools

**Trades & Equipment Rental** — camera/lens rental houses, tool libraries, equipment rental companies

The strongest fit is any business where customers book a physical space + optional specialist, a deposit is required, and a manager or third party approves payment. The Purchase Order workflow is the differentiator most booking plugins don't have.

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- Google Cloud project with Calendar API enabled (for calendar sync)

## Installation

1. Download the latest release zip
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Bookings → Fix Products → Fix All Products Now**
5. Go to **Bookings → Settings** and configure your deposit percentage and payment instructions
6. Import your products via **WooCommerce → Products → Import** using the provided CSV template

## Google Calendar Setup

1. Create a project in [Google Cloud Console](https://console.cloud.google.com)
2. Enable the **Google Calendar API**
3. Create an **OAuth 2.0 Client ID** (Web application)
4. Add the redirect URI shown on the plugin's Google Calendar settings page
5. Add your Google account email as a test user under **OAuth consent screen → Test users**
6. Enter your Client ID and Secret in **Bookings → Google Calendar**
7. Click **Connect to Google Calendar**
8. Create one Google Calendar per room, then map them in **Step 3** on the same page

## Product Categories

Products should be assigned to these categories for the booking system to recognize them:

**Studio Mode:** live-rooms, podcasts, post-production, voice-over, video-editing

**Live Sound Mode:** backline-packages, audio-equipment, instruments, lighting, video-equipment

Products tagged as `hourly` or in hourly categories (live-rooms, podcasts, video-editing) will show the duration selector.

## Settings

All configurable from **Bookings → Settings**:

| Setting | Default | Description |
|---------|---------|-------------|
| Deposit Percentage | 70% | Required deposit on bookings. Set 0 for none, 100 for full payment |
| Payment Instructions | — | Multi-line. Add M-PESA, PayPal, bank details — shown on POs and booking review |
| Studio Address | — | Shown on Purchase Orders |
| Min Lead Time | 24 hours | How far in advance customers must book |
| Max Advance | 90 days | How far ahead customers can book |

## How the Calendar Hold Works

1. Customer adds booking to cart → **PENDING** event created (yellow, configurable hold period)
2. Customer completes payment → event updated to **CONFIRMED** (green, with customer details)
3. Cart abandoned after hold period → pending event automatically deleted by cron
4. Order cancelled or refunded → event deleted
5. Staff blocks time directly in Google Calendar → website shows slot as unavailable

## Shortcode

Place the booking interface on any page:

```
[snowball_booking]
```

## File Structure

```
studio-booking-system/
├── snowball-booking-system.php      # Main plugin file
├── admin/
│   ├── class-sb-admin.php           # Settings, debug, health check
│   └── class-sb-bookings-list.php   # Bookings admin list
├── includes/
│   ├── class-sb-ajax.php            # AJAX handlers (slots, add to cart)
│   ├── class-sb-booking.php         # Booking database operations
│   ├── class-sb-calendar.php        # Calendar UI logic
│   ├── class-sb-google-calendar.php # Google Calendar API integration
│   ├── class-sb-packages.php        # Automatic discount calculations
│   ├── class-sb-product-fix.php     # Product repair tool
│   ├── class-sb-purchase-order.php  # PO generation and rendering
│   ├── class-sb-time-slots.php      # Time slot configuration
│   └── class-sb-woocommerce.php     # WooCommerce cart/order integration
├── public/
│   ├── class-sb-frontend.php        # Frontend assets
│   └── class-sb-shortcodes.php      # Shortcode registration
├── templates/
│   ├── calendar.php                 # Main booking UI (5-step flow)
│   ├── booking-fields.php           # Product page booking fields
│   └── booking-form.php             # Booking form template
└── assets/
    ├── css/
    └── js/
```

## License

GPL v2 or later.
