<?php
/* Everything that makes this installation M&T rather than one of the other
 * academies.
 *
 * THIS IS THE ONLY PER-SITE PHP FILE. Every other .php file in this repository
 * is shared verbatim with SPS, Fungi and the rest, and is written here by
 * tools/sync-backend.php in the SPS repository, which overwrites without
 * asking. It refuses to touch this one.
 *
 * So: if you want to edit a page because "M&T says X and SPS says Y", the answer
 * is a new key in here and brand('key') in the shared page — added to SPS and
 * synced back out. Edit the page here instead and the next sync reverts you.
 *
 * Nothing secret belongs here — this file is deployed into the web root with
 * everything else. Credentials live in ~/private/mtacademy-config.php.
 *
 * STOOD UP 12 Sep 2026, cloned from Equinix because Equinix is the academy
 * whose chrome has the same shape M&T's mark needs: a small square logo with
 * a wordmark beside it. Every value below marked PLACEHOLDER was not supplied
 * and must be before this site is announced.
 */

return [

  /* The academy, and the company whose academy it is. The company's own name
     is "M&T Development"; the academy follows the house pattern of
     "<company> Academy" using the short form, the way "SPS Academy" does. */
  'academy'       => 'M&T Academy',
  'company'       => 'M&T Development',

  /* Used mid-sentence — "for M&T employees", "Fully funded by M&T". */
  'company_short' => 'M&T',

  /* The mark M&T supplied (M-T/_brand-source/MT-AI-f.webp), unaltered. It is a
     90px raster, which is enough for the 34px it is shown at in the nav; ask
     for a vector copy before it is ever printed or shown large. Not redrawn
     as SVG on purpose — an approximation of somebody's trademark is worse
     than a small copy of the real one. */
  'logo'          => 'mt-logo.webp',
  'logo_alt'      => 'M&T Development',

  /* Set beside the logo on the PHP pages, matching the static pages' lockup. The
     tile's own "DEVELOPMENT" lettering is unreadable at 34px, so without this the
     sign-in, contact and admin pages showed an unlabelled square. Plain "M&T":
     chrome_wordmark() escapes it, so do not write &amp; here. */
  'wordmark'      => 'M&T',

  /* Centenary runs the academy for every company, so registrations and reset
     notifications go to Centenary, not to the client. */
  'academy_email'   => 'kgomotso@centenarynetworks.com',

  /* Optional; omitted rather than rendered blank. */
  'enquiries_email' => '',

  /* PLACEHOLDER — nobody has supplied M&T's switchboard. This is the same
     dummy number the other academies started with. It must be replaced before
     anyone is told the site is finished, or a learner will dial it. */
  'phone'         => '012 345 6789',
  'phone_href'    => '0123456789',
  'office_hours'  => 'Monday–Friday, 08:00–17:00 SAST',

  /* PLACEHOLDER examples in the registration form. M&T's real employee
     number format is not known; this only shows the field's shape. */
  'empno_example' => 'e.g. MT1234',
  'dept_example'  => 'e.g. Construction, Property Management, Leasing',

  /* Centenary Networks' accreditation, not the client's. Identical on every
     site because it is one accreditation held by one provider. */
  'accred_no'     => '07-QCTO/SDP180526182035',
  'accred_valid'  => '15 May 2026 – 14 May 2031',

  /* Bumped on any release that changes styles.css or a .js file.
     See asset() in lib/chrome.php for why this is not optional. */
  'asset_version' => '20260912',
];
