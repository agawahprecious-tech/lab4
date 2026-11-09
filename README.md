# Lab 04 - E-Commerce Product Entry

PHP 8 + MySQL/MariaDB form with server-side validation and secure insertion (PDO prepared statements).

## Files
| File | Purpose |
|---|---|
| `index.php` | Form, validation, database insert, extension feature |
| `style.css` | Layout and styling |
| `database.sql` | Creates database `lab04_product_entry` and its table(s) |
| `README.md` | This document |

## Setup
1. Install XAMPP, WAMP, MAMP or any PHP 8.0+ and MySQL 5.7+/MariaDB 10.2+ stack.
2. Import the schema: `mysql -u root -p < database.sql` (or paste it into phpMyAdmin > SQL).
3. Copy this folder into the web root (e.g. `htdocs/`). If your MySQL user or password differs from `root` / empty, edit the `DB_USER` and `DB_PASS` constants at the top of `index.php`.
4. Open `http://localhost/lab04_product_entry/index.php`.
5. Make sure the `uploads/` sub-folder is writable by the web server.

## Database design
`products` (PK `product_id`, UNIQUE `sku`, `image_file` = generated file name only). Full definition in `database.sql`.

## Validation rules
- Product name: required, 3-100 characters.
- SKU: required, 3-30 characters (letters, digits, `-`, `_`), stored upper-case, unique.
- Category: required, from the server list.
- Unit price (XAF): greater than 0, max 2 decimals, up to 8 integer digits.
- Quantity: whole number, 0 or more (max 1,000,000).
- Description: optional, max 1000 characters.
- Image (optional): JPEG or PNG only, max 2 MB. Checked on the server with `finfo` (real MIME type) and `getimagesize`; the client file name and type are ignored. The file is saved as `<32 random hex>.jpg|png` in `uploads/products/` (which has an `.htaccess` blocking script execution) and only that name is stored in the database. If the insert fails, the saved file is deleted.
- Available for sale: checkbox stored as 1/0.

All checks run on the server; HTML5 attributes are only a convenience (the form uses `novalidate` so the server messages can be demonstrated). Invalid submissions keep the entered values (escaped with `htmlspecialchars`) and show a message beside each faulty field.

## Security measures
- Every query uses PDO prepared statements with real (non-emulated) preparation; user input is never concatenated into SQL.
- Success is shown only after `rowCount() === 1`; connection and SQL errors are logged with `error_log` and a generic message is shown to the user.
- CSRF token on every POST, post/redirect/get after success to prevent resubmission.
- All output is HTML-escaped.

## Extension challenge
Inventory table sorted by category then name, with thumbnails.

## Test cases
Run each case, then fill in the last column with the observed result and attach screenshots of the form and of the database query below.

| # | Case | Input | Expected result | Actual result |
|---|---|---|---|---|
| 1 | Valid without image | `Wireless Mouse`, SKU `elec-0001`, Electronics, price 8500, qty 20 | Success; row with SKU `ELEC-0001`, image NULL | _fill in_ |
| 2 | Valid with image | As above with new SKU and a small PNG | Success; `image_file` holds a random name; file exists in `uploads/products/` | _fill in_ |
| 3 | Missing required value | Empty product name | Error under name; no row | _fill in_ |
| 4 | Invalid price | Price `0` and `-5` | Error "greater than 0"; no row | _fill in_ |
| 5 | Invalid quantity | Quantity `2.5` or `-1` | Error "whole number"; no row | _fill in_ |
| 6 | Wrong file type | Upload a `.pdf` or a `.php` renamed to `.png` | Error "Only JPEG or PNG"; no row; no file saved | _fill in_ |
| 7 | Oversized image | Upload an image over 2 MB | Error "larger than 2 MB"; no row | _fill in_ |
| 8 | Duplicate SKU | Re-use `ELEC-0001` (any letter case) | Error "SKU already exists"; still 1 row for that SKU | _fill in_ |

### Database evidence query
```sql
SELECT product_id, sku, product_name, category, unit_price_xaf, quantity, image_file, is_available FROM products ORDER BY product_id DESC;
```

## Assumptions
- Use fictional data only; no real personal data.
- Phone numbers follow the Cameroon mobile format (9 digits starting with 6) because prices are in XAF; change the regular expression in `index.php` to adapt.
