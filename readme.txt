=== trustscript ===
Contributors: nexlifycreater, tssaini
Tags: Tags: woocommerce reviews, review automation, product reviews, verified reviews, ai reviews
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 8.0

Automate WooCommerce review collection — photo & video, verified badges, AI writing assistant, rich snippets, zero PII by design.

== Description ==

Real reviews from real customers — collected automatically, verified 
by design, and private by default.

TrustScript connects to your WooCommerce store and handles everything: 
sending the review request, collecting feedback, verifying the 
purchase, and publishing the review — all on autopilot. No manual 
work. No personal data stored. Ever.

Installed and trusted by WooCommerce stores on the free plan today.

= Collect Reviews — Automatically =

* Customer places an order and it's marked as Delivered
* TrustScript sends a branded email from your own domain
* Customer rates, writes, and optionally attaches photos or videos
* Review publishes instantly with a Verified Purchase badge

= Build Trust — With Verified, Visual Reviews =

* Photo and video reviews with a full lightbox gallery on every 
  product page
* Verified Purchase badge on every review — publicly checkable at 
  nexlifylabs.com/verify-review
* Star ratings and review counts on shop, category, and search pages
* Helpful voting so your best reviews rise to the top naturally

= Protect Privacy — Zero PII by Design =

TrustScript never collects, stores, or transmits customer names, 
email addresses, or any personally identifiable information. Only a 
one-way hashed email (for opt-out) and an order number (for 
verification) are ever used. Full GDPR and CCPA compliant — not by 
checkbox, but by architecture.

= Boost SEO — Automatically =

* JSON-LD structured data injected on every product page
* Star ratings appear directly in Google search results
* Fresh user-generated content added to your store with every review

= Know Everything — With Smart Analytics =

* Track requests sent, opened, converted, and abandoned
* Full per-order timeline from link creation to published review
* One-click reminders for customers who opened but didn't finish
* AI usage tracking — see how many reviews used the writing assistant
* CSV export for all review data

= AI Writing Assistant — Optional, Honest, Always in Their Control =

Customers write their own review first. If they want help, they tap 
Enhance — and get three polished suggestions to choose from, edit, or 
ignore. Their original words are always preserved. AI use is always 
disclosed on the review card and the public verification page.

= Works With Your Page Builder =

The Elementor widget lets you showcase reviews anywhere on your site 
in slider, grid, or masonry layouts — updating automatically as new 
reviews come in.

A free plan is available with 25 review requests per month.
View all plans at https://nexlifylabs.com/pricing
Full documentation at https://nexlifylabs.com/docs

***

*A free plan is available. On paid plans, the AI writing assistant is 
capped monthly — but annual subscribers can collect unlimited standard 
reviews beyond their AI quota. View all plans at https://nexlifylabs.com/pricing*

**Key Features:**

* **Photo & Video Reviews** — Customers can attach photos and videos directly to their review in one click
* **Verified Purchase Badges** — Every review is tied to a real order, with a publicly verifiable verification token
* **AI Writing Assistant** — Optional AI helps customers write clearer reviews; always their words, always their choice
* **Zero PII Collection** — No names, emails, or personal identifiers ever stored on TrustScript servers
* **Smart Analytics** — Full dashboard tracking requests, views, conversions, AI usage, and per-order timelines
* **Auto-Saving Drafts** — Customer progress is saved instantly; they can return days later and pick up where they left off
* **Google Rich Snippets** — JSON-LD structured data added automatically for star ratings in search results
* **Helpful Voting** — Optional thumbs up/down voting on reviews (logged-in users only)
* **Review Count on Product Cards** — Star ratings and review counts shown on shop, category, and search pages
* **Elementor Widget** — Drag-and-drop review showcase with slider, grid, and masonry layouts
* **Smart Refund Handling** — Review links auto-expire when orders are refunded or cancelled in WooCommerce
* **Full Automation** — Review requests, reminders, verification, and publishing all run on autopilot

***

**How It Works:**

1. Customer places an order and it's marked as "Delivered"
2. TrustScript automatically sends a branded review request email from your own domain
3. Customer rates their purchase and writes their review in their own words
4. Optionally, they can attach photos or videos to their review
5. If they choose, AI offers 3 polished rewrite suggestions — they pick one, edit it, or keep their original
6. Review is verified, published to your WooCommerce product page, and appears with a Verified Purchase badge

***

== External Services ==

This plugin connects to the TrustScript API to verify review authenticity.

*Data Sent to TrustScript Service:*
- Order number (used solely for purchase verification and analytics)
- One-way hashed email address
- Webhook URL (where TrustScript posts the completed review back to your site)
- Source identifier (platform type, e.g. woocommerce)
- Rating collection flag (controls whether the rating field is shown in the TrustScript review form)
- Photo collection flag (controls whether photo upload is shown in the review form)
- Video collection flag (controls whether video upload is shown in the review form)
- Product name (optional; controlled by plugin settings)
- Order date (optional; controlled by plugin settings)
- Product image URL (optional; sent if product has an image)
- Product name, product ID, product SKU, and a per-product token (required for multi-product orders only)

Note: Customer review text and star rating are NOT sent by the plugin in the initial request.
They are collected directly by TrustScript's review form and returned to your site via webhook
after the customer submits their review.

*Data Received from TrustScript — Step 1 (Initial API Response):*
- Unique security token (stored for tracking and duplicate prevention; `uniqueToken` for single-product orders, `orderToken` for multi-product orders)
- Duplicate flag (indicates if this order was already processed)
- Customer opted-out flag (indicates if customer has unsubscribed)

*Data Received from TrustScript — Step 2 (Webhook, after customer submits review):*
- Final review text (customer-written, optionally AI-enhanced)
- Star rating (1–5)
- Verification token (for the public review verification page)
- Uploaded media URLs (photos and/or videos attached by the customer)
- Verification hash (for webhook authenticity validation)

*Security & Verification:*
- ✅ Unique token format validated (32+ alphanumeric characters)
- ✅ Webhook timestamp verified (must be within 60 minutes to prevent replay attacks)
- ✅ API key authentication via Bearer token with timing-safe comparison
- ✅ Domain validation ensures API key only works for the registered WordPress site
- ✅ Duplicate prevention: reviews cannot be published twice with the same token
- ✅ Verification tokens allow public review authenticity checks at nexlifylabs.com/verify-review
- ✅ All data transmitted securely over HTTPS only
- ✅ Rate limiting (100 requests per minute per API key to prevent abuse)
- ✅ API key expiration validation (expired keys are rejected with 401 error)
- ✅ Reviews stored in WordPress comment system with full audit trail metadata
- ✅ Zero PII: no names, emails, or personal identifiers stored on TrustScript servers

***

**Page Builder Integration:**

TrustScript works seamlessly with Elementor. Create beautiful social proof sections anywhere on your site with real reviews that update automatically — no manual changes needed.

**Benefits:**

* Display real WooCommerce reviews anywhere on your site
* Automatic updates — new approved reviews appear instantly, no manual work
* Grid, list, slider, and masonry layouts
* Filter by product, rating, or show all reviews
* Full design control with your page builder's styling tools
* Mobile responsive out of the box

For complete setup guides, visit: https://nexlifylabs.com/docs/wordpress

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/trustscript/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Sign up for a free TrustScript account at https://nexlifylabs.com
4. Get your API key from https://nexlifylabs.com/dashboard/api-keys
5. Navigate to TrustScript → Settings in your WordPress admin
6. Enter your API key and test the connection
7. Configure your review settings in TrustScript → Review Settings
8. Start collecting reviews automatically

== Frequently Asked Questions ==

= Do I need a TrustScript account? =
Yes, this plugin connects your WooCommerce store to the TrustScript 
service. You can sign up for free at https://nexlifylabs.com and get 
25 review requests per month at no cost.

= Is the plugin free? =
Yes, the plugin itself is free and open source (GPL-licensed). 
TrustScript offers a free plan with 25 review requests per month, 
with paid plans for higher volume.

= Does this work without WooCommerce? =
No, TrustScript requires WooCommerce to be installed and active. It 
connects to WooCommerce orders to trigger review requests 
automatically.

= Can customers write their own reviews? =
Absolutely. Customers always write their own review first. The AI 
writing assistant is completely optional — it only offers suggestions 
if the customer chooses to use it, and their original words are always 
preserved and shown on the verification page.

= Are photo and video reviews supported? =
Yes. Customers can attach up to 5 photos or videos directly to their 
review. TrustScript automatically compresses images before upload — so 
even large photos taken on a phone or camera (which are often 5–10MB+) 
upload quickly without any errors or file size warnings.

Supported formats: JPG, PNG, and WebP images (compressed automatically); 
MP4 videos up to 100MB. All media is displayed in a lightbox gallery 
on your product page.

= Does TrustScript store personal customer data? =
No. TrustScript follows a strict Zero PII policy. The only data 
handled is the order number (for verification) and a one-way hashed 
email address (for opt-out tracking only). This hash cannot be 
reversed. No names, email addresses, or personal identifiers are 
stored on TrustScript servers.

= Is there a limit on how many reviews I can collect? =
The free plan includes 25 review requests per month. Paid monthly 
plans include a monthly quota for AI-enhanced reviews (250, 500, or 
2,000 depending on your plan). 

If you subscribe to an annual plan, the AI enhancement quota still 
applies — but once you reach it, you can continue collecting unlimited 
standard reviews (without AI assistance) for the rest of that month. 
Your AI quota resets the following month.

View all plans at https://nexlifylabs.com/pricing

= Can I customize the review request email? =
Yes. Email templates are managed from your NexlifyLabs dashboard at 
nexlifylabs.com — not from inside WordPress. You can customize the 
following fields for each email:

- Email subject line
- Header label (small text above the title)
- Email title
- Greeting message
- Thank you message body
- Review request call-to-action text

Available variables:
{customer_name}, {store_name}, {product_name}, {product_image},
{order_number}, {order_date}, {order_total}

{opt_out_link} and {store_name} are inserted automatically — you do 
not need to add them manually.

Product image, product name, order number, order date, and order total 
are all pulled automatically from WooCommerce at send time. This works 
for both single-product and multi-product orders.

At the scheduled send time, TrustScript delivers the template to your 
WordPress site, which then fills in the placeholders with real order 
data and sends the email from your own domain using your own SMTP or 
transactional email service.


= Does TrustScript place any restrictions on what I can put in review 
request emails? =
Yes — and intentionally so. TrustScript enforces FTC and GDPR 
compliance rules on all email templates to protect both your business 
and your customers.

Templates are automatically checked for:

- Incentive language — words like "discount", "coupon", "gift", 
  "reward", "free", "voucher", or "cashback" are not permitted. 
  Offering rewards in exchange for reviews violates FTC guidelines.
- Rating pressure — phrases like "5-star", "highest rating", 
  "favorable review", or "best review" are blocked. Review requests 
  must not push customers toward a specific rating.
- Pressure language — words like "mandatory", "must review", 
  "required", or "have to" are not allowed.
- Review filtering — phrases like "only if you loved it" or 
  "satisfied customers only" are blocked. All customers must be 
  invited to leave feedback regardless of their experience.

Your template must also include honest-feedback language — at least 
one of the words "honest" or "feedback" must appear in the subject 
or body.

If a template violates any of these rules, it will not be saved until 
the issues are resolved. This keeps your review collection practices 
compliant, credible, and trustworthy.

= What is the "Delivered" order status? =
TrustScript adds a custom "Delivered" order status to WooCommerce. 
This ensures review requests go out only after customers actually 
receive their products — not just when the order is shipped — which 
significantly improves response rates and review quality.

= What happens if a customer gets a refund? =
If an order is marked as refunded or cancelled in WooCommerce, the 
review link expires automatically. For partial refunds on multi-
product orders, only the refunded products are removed from the review 
form — the rest of the link stays active.

= Can customers verify that a review is real? =
Yes. Every published review includes a Verified Purchase badge. 
Clicking it shows the review's unique verification token, which can 
be checked at https://nexlifylabs.com/verify-review to confirm the 
review is authentic and see a full transparency log including whether 
AI assistance was used.

= Which page builders are supported? =
TrustScript works with Elementor widgets for displaying reviews with automatic 
updates.

== Technical Architecture ==

**Plugin Integration Points:**

WooCommerce Integration:
- Hooks into `woocommerce_order_status_changed` to trigger review 
  requests
- Stores review metadata in the WordPress comment system with a full 
  audit trail
- Compatible with HPOS (Custom Order Storage) and legacy post meta
- Custom "Delivered" order status via WooCommerce status registration
- Automatic order registry table for sync management

Email & Review Request Flow:
- Custom queue system with batch processing and rate limiting
- Automatic sync via WordPress cron (nightly)
- Manual sync available via the analytics dashboard
- Retry queue for failed email deliveries
- All emails sent from your own domain via your SMTP configuration

Review Verification:
- Each review receives a unique verification token on submission
- Public verification available at nexlifylabs.com/verify-review
- Reviews protected from editing via `wp_update_comment_data` filter
- All changes logged with timestamps

== Screenshots ==

1. Analytics Dashboard — Track requests, views, conversions, and 
   per-order timelines
2. Review Settings — Configure when and how reviews are collected
3. Customer Review Form — Write, attach media, and optionally enhance 
   with AI
4. AI Writing Assistant — Choose from 3 variants or keep the original
5. Verified Purchase Badge & Verification Token modal
6. Public Review Verification page at nexlifylabs.com/verify-review
7. Elementor Review Showcase widget

== Changelog ==

= 1.0.0 =
* Initial public release
* Full WooCommerce integration with automated review requests
* Photo and video review support (up to 5 files per review)
* Verified Purchase badges with public verification tokens
* Optional AI writing assistant with 3 variants per submission
* Zero PII collection — no names, emails, or identifiers stored
* Auto-saving drafts — customers can return and continue anytime
* Smart refund/cancellation handling — review links auto-expire
* Google Rich Snippets (JSON-LD) for star ratings in search results
* Helpful voting system (logged-in users only)
* Star ratings and review counts on all product cards
* Full analytics dashboard with per-order Review Insights
* Elementor widget (slider, grid, masonry layouts)
* Custom "Delivered" WooCommerce order status
* CSV export for all review data

== Upgrade Notice ==

= 1.0.0 =
Initial public release of TrustScript for WooCommerce.

== Privacy Policy & Data Security ==

This plugin connects to the TrustScript service
(https://nexlifylabs.com) to collect and manage product reviews.

**Data Sent TO TrustScript Service:**
- Order number (used for purchase verification and analytics only)
- One-way hashed email address (irreversible; used only for opt-out
  tracking — cannot be decoded back to the original address)
- Webhook URL (tells TrustScript where to send the completed review)
- Source identifier (platform type, e.g. woocommerce)
- Collection flags for rating, photos, and videos (control which fields
  appear in the customer review form)
- Product name and order date (optional; can be disabled in settings)
- Product image URL (optional; sent if the product has an image)
- For multi-product orders: product name, product ID, product SKU, and
  a per-product token are required for each item in the order

**What Is Never Sent:**
- Customer names
- Email addresses in plain text
- Postal addresses or phone numbers
- Payment information
- Any other personally identifiable information

**Data Received FROM TrustScript Service:**

*Step 1 — Initial API response (when review request is registered):*
- Unique security token (used for tracking and duplicate prevention)
- Duplicate flag (indicates if this order was already processed)
- Customer opted-out flag (indicates if customer has unsubscribed)

*Step 2 — Webhook (after customer submits their review):*
- Final review text (customer-written, optionally AI-enhanced)
- Star rating (1–5)
- Verification token (for the public authenticity check page)
- Uploaded media URLs (photos and/or videos attached by the customer)
- AI-generated review variants (if the customer used the writing assistant)
- Verification hash (for webhook authenticity validation)

**GDPR & Privacy Compliance:**
- ✅ Zero PII — no personal data is ever sent to or stored on TrustScript
  servers. No names, email addresses, or identifiers of any kind.
- ✅ Opt-out link included in every review request email — customers can
  remove themselves from future requests with one click
- ✅ One-way hashed email address used only for opt-out tracking —
  cannot be reversed or decoded
- ✅ No third-party data sharing — not possible by design, since no
  personal data is ever received by TrustScript
- ✅ No consent checkbox required — because no personal data is
  collected, GDPR consent popups and checkout opt-ins are not necessary (means, all orders are eligible for review request)
- ✅ Review deletion requests can be submitted via the store owner or by
  contacting support at support@nexlifylabs.com

By using this plugin, you agree to TrustScript's Privacy Policy:
https://nexlifylabs.com/privacy

== Support ==

- Documentation: https://nexlifylabs.com/docs
- Support Portal: https://nexlifylabs.com/contact
- Email: support@nexlifylabs.com
