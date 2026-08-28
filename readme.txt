=== Event Booking Slots ===
Contributors: yarikkanonirov
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Event days with capped time slots, exposed to Elementor Pro Forms as a custom "Booking Slot" field.

== Description ==

Create a Booking Event, give it dates and time slots with a capacity each, then drop the
"Booking Slot" field into any Elementor Pro form and point it at that event. Visitors pick
a day and a time; the seat is held the moment the form validates and released again if the
submission fails for any other reason.

Capacity is enforced on the server with a single conditional UPDATE, so two people racing
for the last seat cannot both win it.

= What it adds =

* A "Booking Events" post type with a calendar editor for dates, times and capacities.
* A "Booking Slot" field for Elementor Pro forms, with options to hide the radio
  circle and to leave the form filled in after sending.
* A bookings list with search, per-event filtering, CSV export, and cancel/delete.
* Duplicate protection: block a second booking from the same email or phone number.

= Duplicate bookings =

Under **Bookings -> Settings** you choose whether a repeat submission is matched on the
email address, the phone number, or both, whether to look only within the same event or
across every event on the site, and exactly what the visitor is told when they are turned
away. The message supports the placeholders `{name}`, `{email}`, `{phone}`, `{event}`,
`{date}` and `{time}` — the date and time being those of the booking the visitor already has.

Phone numbers are compared on their digits only, keeping the last nine, so `050-123 4567`
and `+972 50 1234567` are recognised as the same number. Sites outside Israel can change
that rule with the `ebs_normalize_phone` filter, before any bookings exist.

= Filters =

* `ebs_show_full_slots` — whether fully booked slots stay listed as unavailable.
* `ebs_normalize_phone` — how a phone number is reduced to its comparable form.
* `ebs_duplicate_booking` — override the duplicate decision for one submission.

= Actions =

* `ebs_booking_created` — a booking row exists and its seats are held.
* `ebs_booking_cancelled` — a booking was cancelled and its seats returned.
* `ebs_booking_deleted` — a booking row was removed and its seats returned.
* `ebs_duplicate_rejected` — a submission was turned away as a duplicate.

== Cancelling versus deleting ==

**Cancel** frees the seat but keeps the row, so the booking still appears in the list and
in exports marked as cancelled. By default a cancelled person may book again.

**Delete** frees the seat and removes the row entirely. It cannot be undone, and the
person is then treated as new by the duplicate check. A booking that was already cancelled
gave its seat back at that point, so deleting it does not credit the slot a second time.

== Keeping the form after submission ==

Elementor clears every field once a form is sent, and returns a multi-step form to
step one. On a long booking form that leaves the visitor looking at an empty step one
with the confirmation somewhere below, as though nothing happened.

The **Do not reset the form after sending** option on the Booking Slot field prevents
both. It only applies to forms containing a Booking Slot field; every other form on
the site keeps Elementor's normal behaviour.

A rejected submission keeps the visitor where they are too. Elementor moves a rejected
form to the step holding the invalid field, so a duplicate is reported against the email
or phone that matched rather than against the booking field on the first step -- being
sent back to the beginning to be told your email is already used helps nobody.

The slot list is still refreshed after a rejection, in case somebody took the last seat
while the visitor was typing, but the date and time they had chosen are carried across
that refresh. A slot that has since filled up simply comes back unselected.

== Requirements ==

Elementor Pro is required for the form field. Without it the events and slots still work,
but nothing can display them on the site.

== A note on the scarcity display ==

The Booking Slot field can show a reduced "places left" figure instead of the true count.
The number shown is never higher than the places that actually exist and only ever falls,
but it is still not the real availability. Check your local consumer-protection rules
before switching it on.

== Changelog ==

= 0.4.2 =
* A duplicate rejection is now reported against the email or phone field that
  actually matched, instead of the booking field. Elementor sends a rejected
  multi-step form to the step holding the invalid field, and the booking field is
  on the first step -- so the visitor was thrown back to the beginning to be told
  their email was already used.
* Fixed being unable to move forward again after that. Elementor's goToStep()
  moves the form without updating its own step counter, so the jump left the
  counter pointing at the step the visitor had been on; the next "Next" then asked
  for a step past the end and the form went blank. Reporting the error on the step
  the visitor is already on means Elementor never moves, and never drifts.
* Added a recovery for a form left showing no step at all, whatever caused it.

= 0.4.1 =
* Fixed two steps showing at once after a rejected submission. Keeping the step was
  done by putting the classes back after Elementor had reset them, which left
  Elementor's own idea of the current step disagreeing with the DOM. Elementor is now
  stopped from resetting the steps at all, so the two never diverge.
* Fixed the field clearing on sites with a "delay JavaScript" optimiser. The guard was
  bound only if jQuery already existed, and this script deliberately loads ahead of a
  delayed jQuery, so it silently never bound. It no longer needs jQuery.
* Fixed Elementor's inline field error landing on top of the slot list. It is forced
  back into the flow and shown above the date picker.
* A rejected submission no longer costs the visitor the date and time they picked:
  availability still refreshes, but the choice is carried across it.
* Fixed the previous slot list staying on screen when the field was re-initialised.

= 0.4.0 =
* Added duplicate booking protection, matched on email address and/or phone number.
* Added Delete to the bookings list: removes the row and frees the seat for good.
* New field option: hide the radio circle, so each time is a plain clickable row.
* New field option: do not reset the form after sending. Elementor empties every
  field on success and returns a multi-step form to step one; this leaves the form
  as the visitor left it, with the confirmation beside their answers.
* Added a Bookings -> Settings screen with the wording shown to a repeat visitor.
* Bookings now store a normalised phone number, indexed for the duplicate lookup.
  Existing rows are backfilled on upgrade.
* Assets are versioned by file modification time instead of the plugin version.
* Removed the last inline script from the admin.
* Added directory-protection index.php files throughout.

= 0.3.0 =
* Calendar editor for dates and time slots.
* Slot end times, scarcity display, and the multi-step summary.

== Upgrade Notice ==

= 0.4.2 =
Bug fixes only, no database changes. Clear any page cache after upgrading.

= 0.4.1 =
Bug fixes only, no database changes. Clear any page cache after upgrading: the assets are
versioned by file modification time, but cached HTML still points at the previous ones.

= 0.4.0 =
Adds a column and two indexes to the bookings table, and backfills existing rows on the
first admin request after upgrading. Duplicate checking is on by default, matching on both
email and phone within the same event — review it under Bookings -> Settings.
