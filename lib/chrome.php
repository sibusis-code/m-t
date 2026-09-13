<?php
declare(strict_types=1);

/* The page furniture — nav, footer, logo, disclaimer — in one place.
 *
 * WHY THIS EXISTS
 *
 * Four academies run this same application: SPS, Fungi, Equinix, Maziv. Before
 * this file, each one carried its own copy of every page, and the copies
 * differed only in the nouns: a logo filename, a company name, "SP1234" instead
 * of "FU1234". That arrangement has one specific failure mode, and it is not
 * hypothetical — it is what the four copies of styles.css already do. A fix
 * gets made in the repository the person happened to be working in, and the
 * other three keep the bug. The fix that matters is a security fix, and the
 * academy that misses it is the one nobody looked at that week.
 *
 * So the pages are now identical files, byte for byte, and everything that
 * differs between academies is data in lib/brand.php. That file is the ONLY
 * per-site PHP file, and tools/sync-backend.php refuses to copy it.
 *
 * WHY BRANDING IS IN THE REPOSITORY AND NOT IN THE SERVER CONFIGURATION
 *
 * The obvious place for "company name" is the configuration file next to the
 * database password. It is the wrong place. Branding is not a secret, it is
 * content: it gets reviewed, corrected, and argued over, and when it changes we
 * want to know who changed it and when. In ~/private/fungiacademy-config.php it
 * would be hand-typed over SFTP with no history and no review, and a typo in a
 * company's registered name would sit on their site until somebody noticed.
 *
 * Credentials stay in the configuration file. Nouns stay in git.
 */

/**
 * One branding fact.
 *
 * Missing keys are fatal rather than blank. A footer that silently renders
 * "© 2026 . All rights reserved." is exactly the kind of thing that goes live,
 * because nothing errored and nobody reads the footer.
 */
function brand(string $key): string
{
    static $brand = null;
    if ($brand === null) {
        $file = __DIR__ . '/brand.php';
        if (!is_file($file)) {
            app_fail('This installation has no lib/brand.php. Every academy needs '
                   . 'its own; copy one from another academy and change the nouns.');
        }
        $brand = require $file;
        if (!is_array($brand)) {
            app_fail('lib/brand.php must return an array.');
        }
    }
    if (!array_key_exists($key, $brand)) {
        app_fail('lib/brand.php has no "' . $key . '". Add it — a blank brand value '
               . 'renders as an empty gap that nobody notices.');
    }
    return (string) $brand[$key];
}

/** True when the key is present and not empty — for the genuinely optional ones. */
function brand_has(string $key): bool
{
    static $brand = null;
    if ($brand === null) $brand = require __DIR__ . '/brand.php';
    return isset($brand[$key]) && (string) $brand[$key] !== '';
}

/**
 * A local asset URL with the cache-busting version on it.
 *
 * Hard-won. The parent site sets mod_expires on css and js, and mod_expires IS
 * inherited into subdirectories even though mod_rewrite is not. Returning
 * visitors held month-old JavaScript and a deploy did nothing for them at all.
 * The .htaccess header fixes that going forward, but only for a browser that
 * asks — this is what fixes it for a browser that does not.
 *
 * One constant per site, so a release changes a single line rather than
 * fourteen assets across twenty-three files by hand.
 */
function asset(string $path): string
{
    return $path . '?v=' . brand('asset_version');
}

/**
 * The name set beside an icon-only logo — "CRICKET SA", "M&T", "EQUINIX".
 *
 * OPTIONAL, AND ASKED FOR THROUGH brand_has() FIRST (13 Sep 2026). Some academies'
 * logos carry the company name in the artwork and need nothing beside them; others
 * are a bare mark. The static .html pages always paired a bare mark with a
 * .brand-word span, but these PHP pages never did, so contact, sign-in and every
 * admin page showed an unlabelled mark — on Cricket SA's, just a green and gold
 * ball, with nothing on the page saying whose academy it was.
 *
 * brand() fails the whole page on a missing key, by design. Calling it for a key
 * the logo-with-lettering academies do not define would have taken down every PHP
 * page on SPS, Fungi, Maziv and Tracker on their next deploy. So it is only called
 * once brand_has() says the site set one; everyone else renders exactly as before.
 */
function chrome_wordmark(): string
{
    return brand_has('wordmark')
        ? '<span class="brand-word">' . e(brand('wordmark')) . '</span>'
        : '';
}

function chrome_logo(string $class = 'brand'): string
{
    return '<a href="./" class="' . e($class) . '"><img src="' . e(brand('logo'))
         . '" alt="' . e(brand('logo_alt')) . '">' . chrome_wordmark() . '</a>';
}

/**
 * The hamburger. EVERY nav needs one — this is not a decoration.
 *
 * Below 900px `.nav-links` is `display:none` and only `.nav-links.open` shows,
 * and site.js adds that class only when it finds BOTH `#navToggle` and
 * `#navLinks`. So a nav rendered without this button is not a nav that looks a
 * bit plain on a phone: it is a nav with no links at all, permanently.
 *
 * That is what happened to the admin pages. They had four links and a sign-out,
 * none of which existed on a phone, and it went unnoticed because it is
 * invisible on a laptop — which is where a page like that gets built and
 * checked. The auth pages had it too. If you add a fifth nav variant, give it
 * a toggle and an id="navLinks", and open it at 390px before you believe it.
 */
function chrome_nav_toggle(): string
{
    return '<button class="nav-toggle" id="navToggle" aria-label="Menu">'
         . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" style="width:26px;height:26px;display:block">'
         . '<path d="M3 6h18M3 12h18M3 18h18"/></svg></button>';
}

/**
 * The sign-out control.
 *
 * A POST form and not a link, deliberately. Signing out over GET means any page
 * anywhere can sign our learners out with an <img src>, and it means a link
 * prefetcher can do it by accident. The CSRF token comes along for the same
 * reason it does everywhere else.
 */
function chrome_signout(string $label = 'Sign out'): string
{
    return '<form method="POST" action="logout" style="display:inline">'
         . csrf_field()
         . '<button type="submit" class="linkish">' . e($label) . '</button></form>';
}

/**
 * The navigation bar.
 *
 * Four shapes, because there are four kinds of page and they want different
 * things within reach:
 *
 *   site    — the public site's full menu. Someone reading the catalogue.
 *   auth    — sign in, forgot, reset. Stripped to almost nothing: a person
 *             trying to get into their account should not be offered six ways
 *             to wander off, one of which is the page they just came from.
 *   learner — signed in and studying. Their own learning first, sign out reachable.
 *   admin   — the back office. Not the public site's menu at all.
 */
function chrome_nav(string $variant, array $o = []): void
{
    $active = (string) ($o['active'] ?? '');
    $on = static fn(string $k): string => $active === $k ? ' class="active"' : '';

    echo '<nav id="nav">' . "\n  " . '<div class="nav-inner">' . "\n    "
       . chrome_logo() . "\n";

    if ($variant === 'site') {
        echo '    <div class="nav-links" id="navLinks">' . "\n";
        echo '      <a href="./" data-nav="home">Home</a>' . "\n";
        echo '      <a href="about" data-nav="about">About</a>' . "\n";
        echo '      <a href="courses" data-nav="courses">Courses</a>' . "\n";
        /* ⚠ trainers.html is NOT on the sync manifest — like every other .html it
           carries its own site's chrome, so each academy needs its own copy. This
           file IS synced. Copy trainers.html (and the trainer styles in
           styles.css) into a target repo BEFORE syncing chrome.php into it, or
           this link 404s on that site. Same footing as the Graduates link below,
           which has always had the same requirement. */
        echo '      <a href="trainers" data-nav="trainers">Trainers</a>' . "\n";
        echo '      <a href="graduates" data-nav="graduates">Graduates</a>' . "\n";
        echo '      <a href="contact" data-nav="contact"' . $on('contact') . '>Contact</a>' . "\n";
        echo '      <a href="profile" data-nav="profile" class="nav-profile" title="Your profile">'
           . '<span class="np-av" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" '
           . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
           . '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'
           . '</svg></span><span class="np-label">Profile</span></a>' . "\n";
        echo '      <a href="contact" class="nav-cta">Upskill Yourself</a>' . "\n";
        echo '    </div>' . "\n";
        echo '    ' . chrome_nav_toggle() . "\n";

    } elseif ($variant === 'auth') {
        echo '    <div class="nav-links" id="navLinks">' . "\n";
        echo '      <a href="./" data-nav="home">Home</a>' . "\n";
        echo '      <a href="courses" data-nav="courses">Courses</a>' . "\n";
        echo ($o['tail'] ?? 'contact') === 'signin'
            ? '      <a href="login">Sign in</a>' . "\n"
            : '      <a href="contact" data-nav="contact">Contact</a>' . "\n";
        echo '    </div>' . "\n";
        echo '    ' . chrome_nav_toggle() . "\n";

    } elseif ($variant === 'learner') {
        echo '    <div class="nav-links" id="navLinks">' . "\n";
        echo '      <a href="./" data-nav="home">Home</a>' . "\n";
        echo '      <a href="courses" data-nav="courses">Courses</a>' . "\n";
        echo '      <a href="my" class="active">My learning</a>' . "\n";
        /* A trainer needs the same way through from the learner pages that an
           administrator has, or the only route to their own page is typing the
           URL. Two keys rather than one so the link can say where it goes. */
        if (!empty($o['admin'])) {
            echo '      <a href="admin">Administration</a>' . "\n";
        } elseif (!empty($o['trainer'])) {
            echo '      <a href="trainer">My learners</a>' . "\n";
        }
        echo '      <a href="contact" data-nav="contact">Contact</a>' . "\n";
        echo '      ' . chrome_signout() . "\n";
        echo '    </div>' . "\n";
        echo '    ' . chrome_nav_toggle() . "\n";

    } elseif ($variant === 'admin') {
        echo '    <div class="nav-links" id="navLinks">' . "\n";
        /* Two navs share this variant: a trainer signs in to the same chrome as
           an administrator and only the links differ. Registrations, Accounts,
           Progress and B-BBEE are administration; Material, Reading and Quizzes
           are the course, and a trainer reads those. The pages enforce this
           themselves — the nav only decides what is worth offering. */
        $staff = function_exists('is_trainer') && is_trainer();
        if (!$staff) {
            echo '      <a href="admin"' . $on('admin') . '>Registrations</a>' . "\n";
            echo '      <a href="admin-progress"' . $on('admin-progress') . '>Progress</a>' . "\n";
            echo '      <a href="admin-users"' . $on('admin-users') . '>Accounts</a>' . "\n";
        } else {
            echo '      <a href="trainer"' . $on('trainer') . '>My learners</a>' . "\n";
        }
        echo '      <a href="admin-materials"' . $on('admin-materials') . '>Material</a>' . "\n";
        echo '      <a href="admin-lessons"' . $on('admin-lessons') . '>Reading</a>' . "\n";
        echo '      <a href="admin-quizzes"' . $on('admin-quizzes') . '>Quizzes</a>' . "\n";
        /* Offered to both, unlike everything above: an admin schedules classes
           and a trainer marks the register of the ones they facilitate. It is
           the one page in this nav where a trainer can write — the narrow
           exception argued for in lib/classes.php. */
        echo '      <a href="admin-classes"' . $on('classes') . '>In-person</a>' . "\n";
        /* HR only, on Kgomotso's instruction of 25 Aug 2026 — it is in the admin
           nav and nowhere else. It was briefly in the public footer; if you find
           yourself adding it back to the 'site' variant above, re-read the note
           at the top of scorecard.php first. */
        if (!$staff) {
            echo '      <a href="scorecard"' . $on('scorecard') . '>B-BBEE</a>' . "\n";
        }
        echo '      <a href="./">View the site</a>' . "\n";
        echo '      ' . chrome_signout('Sign out (' . (string) ($o['name'] ?? '') . ')') . "\n";
        echo '    </div>' . "\n";
        echo '    ' . chrome_nav_toggle() . "\n";

    } else {
        app_fail('Unknown nav variant "' . $variant . '".');
    }

    echo '  </div>' . "\n" . '</nav>' . "\n";
}

/**
 * The footer disclaimer, assembled from parts rather than stored as prose.
 *
 * It was stored as prose — four paragraphs per site across four sites — and it
 * had already drifted. Fungi's progress page claimed copyright for "Fungi —
 * Digital Utility" while its contact page said "Fungi Utilities (Pty) Ltd":
 * two different entities named on two pages of one site, because somebody
 * copied a paragraph and edited half of it.
 */
function chrome_disclaimer(string $extra = '', bool $employees = false): string
{
    $s = brand('academy') . ' is the in-house training academy of ' . brand('company');
    if ($employees) $s .= ', for ' . brand('company_short') . ' employees';
    $s .= '. ';
    if ($extra !== '') $s .= rtrim($extra) . ' ';
    $s .= brand('academy') . ' operates in association with Centenary Networks. '
        . '© 2026 ' . brand('company') . '. All rights reserved.';
    return $s;
}

/**
 * The QCTO accreditation block.
 *
 * The accreditation belongs to Centenary Networks and is therefore the same on
 * all four sites — Centenary holds it, not the client company. Worth saying out
 * loud, because "give each academy its own accreditation number" is a
 * reasonable-sounding change that would put a false statement on three sites.
 */
function chrome_accred(): string
{
    return '<div class="foot-accred" style="max-width:none;border-bottom:1px solid '
         . 'rgba(255,255,255,.1);padding-bottom:22px;margin-bottom:20px">' . "\n"
         . '      <strong>Accreditation</strong>' . "\n"
         . '      <span>Accredited qualifications are delivered in association with '
         . 'Centenary Networks (Pty) Ltd, accredited by the Quality Council for Trades '
         . 'and Occupations (QCTO) as a Skills Development Provider. Accreditation No. '
         . e(brand('accred_no')) . ', valid ' . e(brand('accred_valid')) . '.</span>' . "\n"
         . '    </div>';
}

/**
 * The footer.
 *
 *   slim — the account pages. Someone signing in does not need a site map.
 *   site — the public pages: brand, links, accreditation, disclaimer.
 */
function chrome_footer(string $variant, array $o = []): void
{
    echo '<footer>' . "\n" . '  <div class="wrap">' . "\n";

    if ($variant === 'site') {
        echo '    <div class="foot-top">' . "\n";
        echo '      <div class="foot-brand"><img src="' . e(brand('logo')) . '" alt="'
           . e(brand('logo_alt')) . '">' . chrome_wordmark() . '</div>' . "\n";
        echo '      <div class="foot-nav">' . "\n";
        foreach ([
            './'           => 'Home',
            'about'        => 'About',
            'courses'      => 'Courses',
            'trainers'     => 'Trainers',
            'graduates'    => 'Graduates',
            'profile'      => 'Profile',
            'contact'      => 'Contact',
            'privacy'      => 'Privacy',
        ] as $href => $label) {
            echo '        <a href="' . $href . '">' . $label . '</a>' . "\n";
        }
        echo '      </div>' . "\n";
        echo '    </div>' . "\n";
        if ($o['accred'] ?? true) echo '    ' . chrome_accred() . "\n";
    } elseif ($variant !== 'slim') {
        app_fail('Unknown footer variant "' . $variant . '".');
    }

    echo '    <p class="disclaimer">'
       . e(chrome_disclaimer((string) ($o['extra'] ?? ''), (bool) ($o['employees'] ?? false)))
       . '</p>' . "\n";
    echo '  </div>' . "\n" . '</footer>' . "\n";

    /* Deliberately NOT emitting <script src="site.js"> here, though every page
       that calls this loads it. Two pages load three more scripts after the
       footer and one of them reads what the others set up, so if this function
       owned site.js it would silently reorder them. A footer helper is not the
       right place to be making decisions about script execution order. */
}
