<?php
if (!defined('ABSPATH')) exit;

/** Publika sidor: kurslistor, kurssida med samarbetspartner och informationssidor, anmälan, tacksida och avregistrering. */
final class EKA_Frontend {

    public static function init() {
        add_shortcode('enkel_kurslista', [__CLASS__, 'course_list_shortcode']);
        add_shortcode('enkel_kursdatumlista', [__CLASS__, 'course_date_list_shortcode']);
        add_filter('the_content', [__CLASS__, 'thanks_box'], 8);
        add_filter('the_content', [__CLASS__, 'course_content']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_nopriv_eka_register', [__CLASS__, 'handle_registration']);
        add_action('admin_post_eka_register', [__CLASS__, 'handle_registration']);
        add_action('admin_post_nopriv_eka_unsubscribe', [__CLASS__, 'handle_unsubscribe']);
        add_action('admin_post_eka_unsubscribe', [__CLASS__, 'handle_unsubscribe']);
    }

    public static function assets() {
        wp_enqueue_style('eka-style', EKA_URL . 'assets/eka.css', [], EKA_VERSION);
        if (is_singular('eka_course')) wp_enqueue_script('eka-front', EKA_URL . 'assets/eka-front.js', [], EKA_VERSION, true);
    }

    private static function course_query($atts) {
        $atts = shortcode_atts(['kategori' => ''], $atts);
        $args = ['post_type' => 'eka_course', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC'];
        if ($atts['kategori'] !== '') $args['tax_query'] = [['taxonomy' => 'eka_category', 'field' => 'slug', 'terms' => array_map('trim', explode(',', $atts['kategori']))]];
        return new WP_Query($args);
    }

    /* ---------------- Shortcodes ---------------- */

    public static function course_list_shortcode($atts) {
        $q = self::course_query($atts);
        ob_start(); ?><div class="eka-grid"><?php while ($q->have_posts()): $q->the_post(); $price = (float) get_post_meta(get_the_ID(), '_eka_price', true); ?>
        <article class="eka-card"><?php if (has_post_thumbnail()) the_post_thumbnail('medium_large'); ?><div class="eka-card-body"><h2><?php the_title(); ?></h2><div><?php echo esc_html(wp_trim_words(get_the_excerpt(), 30)); ?></div><p><strong><?php echo esc_html(EKA_Core::money($price)); ?> kr</strong></p><a class="eka-button" href="<?php the_permalink(); ?>">Läs mer och anmäl dig</a></div></article>
        <?php endwhile; wp_reset_postdata(); ?></div><?php return ob_get_clean();
    }

    public static function course_date_list_shortcode($atts) {
        $q = self::course_query($atts);
        $items = [];
        while ($q->have_posts()) { $q->the_post(); foreach (EKA_Core::upcoming_sessions(get_the_ID()) as $s) {
            $items[] = ['sort' => EKA_Core::session_start($s)->getTimestamp(), 'date' => $s['date'], 'start' => $s['start'], 'title' => get_the_title(), 'url' => get_permalink()];
        }} wp_reset_postdata();
        usort($items, function($a, $b) { return $a['sort'] <=> $b['sort'] ?: strcmp($a['title'], $b['title']); });
        ob_start(); ?><div class="eka-date-list"><?php if (!$items): ?><p>Det finns inga kommande kurstillfällen just nu.</p><?php endif; ?><?php foreach ($items as $item): ?>
        <a class="eka-date-item" href="<?php echo esc_url($item['url']); ?>"><span class="eka-calendar-icon" aria-hidden="true"><span></span></span><span><strong><?php echo esc_html(wp_date(get_option('date_format'), strtotime($item['date']))); ?>, <?php echo esc_html($item['start']); ?></strong><small><?php echo esc_html($item['title']); ?></small></span></a>
        <?php endforeach; ?></div><?php return ob_get_clean();
    }

    /* ---------------- Kurssidan ---------------- */

    public static function course_content($content) {
        if (!is_singular('eka_course') || !in_the_loop() || !is_main_query()) return $content;
        $course_id = get_the_ID();
        return $content . self::partners_html($course_id) . self::info_pages_html($course_id) . self::form_html($course_id);
    }

    private static function partners_html($course_id) {
        $partners = EKA_Core::partners($course_id);
        if (!$partners) return '';
        $html = '<section class="eka-partners"><h2>I samarbete med</h2><ul>';
        foreach ($partners as $p) {
            $inner = !empty($p['logo_id']) ? wp_get_attachment_image((int) $p['logo_id'], 'medium', false, ['alt' => $p['name'], 'loading' => 'lazy']) : '';
            if ($inner === '') $inner = '<span class="eka-partner-name">' . esc_html($p['name']) . '</span>';
            $html .= '<li>' . (!empty($p['url']) ? '<a href="' . esc_url($p['url']) . '" target="_blank" rel="noopener">' . $inner . '</a>' : $inner) . '</li>';
        }
        return $html . '</ul></section>';
    }

    private static function info_pages_html($course_id) {
        $links = EKA_Core::info_pages($course_id);
        if (!$links) return '';
        $html = '<section class="eka-info-pages"><h2>Bra att veta</h2><ul>';
        foreach ($links as $l) $html .= '<li><a href="' . esc_url($l['url']) . '">' . esc_html($l['label']) . '</a></li>';
        return $html . '</ul></section>';
    }

    private static function participant_row($i, $nr) { ?>
        <fieldset class="eka-participant"><legend>Deltagare <?php echo esc_html($nr); ?></legend>
        <label>Namn<input name="eka_participants[<?php echo esc_attr($i); ?>][name]" required></label>
        <label>Personnummer<input name="eka_participants[<?php echo esc_attr($i); ?>][pnr]" placeholder="ÅÅÅÅMMDD-NNNN" inputmode="numeric" pattern="[0-9]{6,8}[-+]?[0-9]{4}" required></label>
        </fieldset>
    <?php }

    private static function form_html($course_id) {
        $price = (float) get_post_meta($course_id, '_eka_price', true);
        $sessions = EKA_Core::upcoming_sessions($course_id);
        $percent = EKA_Core::deposit_percent($course_id);
        $pnr = EKA_Core::requires_personal_number($course_id);
        ob_start(); ?>
        <section class="eka-registration" id="anmalan"><h2>Anmälan</h2>
        <p><strong>Kostnad:</strong> <?php echo esc_html(EKA_Core::money($price)); ?> kr per deltagare.
        <?php if ($price > 0): ?><?php echo $percent >= 100 ? 'Hela kursavgiften betalas via Swish vid anmälan.' : esc_html(sprintf('Vid anmälan betalas en föranmälningsavgift på %s %% via Swish.', rtrim(rtrim(number_format($percent, 1, ',', ''), '0'), ','))); ?><?php endif; ?></p>
        <?php if (!$sessions): ?><p>Det finns inga bokningsbara kurstillfällen just nu.</p><?php else: ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="eka-form"<?php echo $pnr ? ' data-pnr="1"' : ''; ?>>
        <input type="hidden" name="action" value="eka_register"><input type="hidden" name="course_id" value="<?php echo (int) $course_id; ?>"><?php wp_nonce_field('eka_register_' . $course_id, 'eka_nonce'); ?>
        <label>Kurstillfälle<select name="session" required><option value="">Välj ett tillfälle</option><?php foreach ($sessions as $s): $key = EKA_Core::session_key($s); $left = max(0, (int) $s['max'] - EKA_Core::booked_count($course_id, $key)); ?><option value="<?php echo esc_attr($key); ?>" <?php disabled($left, 0); ?>><?php echo esc_html(EKA_Core::session_label($s) . ($left ? ' – ' . $left . ' platser kvar' : ' – Fullbokad')); ?></option><?php endforeach; ?></select></label>
        <fieldset class="eka-contact"><legend>Kontaktperson</legend>
        <label>Namn<input name="name" autocomplete="name" required></label><label>E-post<input type="email" name="email" autocomplete="email" required></label><label>Telefon<input type="tel" name="phone" autocomplete="tel" required></label>
        </fieldset>
        <label>Antal deltagare<input type="number" name="participants" value="1" min="1" max="<?php echo (int) EKA_Core::MAX_PARTICIPANTS; ?>" required></label>
        <?php if ($pnr): ?>
        <div class="eka-participants"><?php self::participant_row(0, 1); ?></div>
        <template class="eka-participant-template"><?php self::participant_row('__I__', '__NR__'); ?></template>
        <p class="eka-hint">Personnummer behövs för den här kursen och hanteras konfidentiellt.</p>
        <?php endif; ?>
        <label class="eka-check"><input type="checkbox" name="newsletter" value="1"> Jag vill gärna få information om kommande kurser.</label>
        <label class="eka-check"><input type="checkbox" name="privacy" value="1" required> Jag godkänner att uppgifterna sparas för att hantera anmälan.</label>
        <button class="eka-button" type="submit">Skicka anmälan</button></form><?php endif; ?></section>
        <?php return ob_get_clean();
    }

    /* ---------------- Anmälan ---------------- */

    private static function fail($message) {
        wp_die(esc_html($message) . '<p><a href="javascript:history.back()">← Tillbaka till formuläret</a></p>', 'Anmälan kunde inte skickas', ['response' => 400]);
    }

    public static function handle_registration() {
        $course_id = absint($_POST['course_id'] ?? 0);
        if (!$course_id || get_post_type($course_id) !== 'eka_course' || get_post_status($course_id) !== 'publish' || !isset($_POST['eka_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eka_nonce'])), 'eka_register_' . $course_id)) self::fail('Ogiltig förfrågan. Ladda om sidan och försök igen.');
        if (empty($_POST['privacy'])) self::fail('Du måste godkänna hanteringen av personuppgifter.');

        $session = sanitize_text_field(wp_unslash($_POST['session'] ?? ''));
        $selected = null;
        foreach (EKA_Core::upcoming_sessions($course_id) as $candidate) if (hash_equals(EKA_Core::session_key($candidate), $session)) { $selected = $candidate; break; }
        if (!$selected) self::fail('Kurstillfället är inte giltigt eller har redan startat.');

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        if (!$name || !is_email($email) || !$phone) self::fail('Kontrollera kontaktuppgifterna.');
        $participants = min(EKA_Core::MAX_PARTICIPANTS, max(1, absint($_POST['participants'] ?? 1)));

        $people = [];
        if (EKA_Core::requires_personal_number($course_id)) {
            $seen = [];
            foreach (array_values((array) ($_POST['eka_participants'] ?? [])) as $i => $row) {
                $pname = sanitize_text_field(wp_unslash($row['name'] ?? ''));
                $raw = sanitize_text_field(wp_unslash($row['pnr'] ?? ''));
                if ($pname === '' && $raw === '') continue;
                $pnr = EKA_Core::normalize_personal_number($raw);
                if ($pname === '' || !$pnr) self::fail(sprintf('Kontrollera namn och personnummer för deltagare %d.', $i + 1));
                if (isset($seen[$pnr])) self::fail('Samma personnummer har angetts för flera deltagare.');
                $seen[$pnr] = true;
                $people[] = ['name' => $pname, 'pnr' => $pnr];
            }
            if (count($people) !== $participants) self::fail(sprintf('Ange namn och personnummer för alla %d deltagare.', $participants));
        }

        $price = (float) get_post_meta($course_id, '_eka_price', true);
        $total = round($price * $participants, 2);
        $deposit = round($total * EKA_Core::deposit_percent($course_id) / 100, 2);
        $reg = ['course_id' => $course_id, 'session_key' => $session, 'name' => $name, 'email' => $email, 'phone' => $phone, 'participants' => $participants, 'total_amount' => $total, 'deposit_amount' => $deposit, 'payment_reference' => 'KURS-' . strtoupper(wp_generate_password(8, false, false)), 'payment_status' => 'pending', 'created_at' => current_time('mysql')];

        // Platskontroll och sparande under lås, så att två samtidiga anmälningar inte kan ta samma plats.
        global $wpdb;
        if (!EKA_Core::lock_session($course_id, $session)) self::fail('Det kommer in många anmälningar just nu. Försök igen om en stund.');
        $available = max(0, (int) $selected['max'] - EKA_Core::booked_count($course_id, $session));
        if ($participants > $available) {
            EKA_Core::unlock_session($course_id, $session);
            self::fail($available ? sprintf('Det finns bara %d platser kvar på detta kurstillfälle.', $available) : 'Kurstillfället är tyvärr fullbokat.');
        }
        $ok = $wpdb->insert(EKA_DB::table('registrations'), $reg, ['%d', '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%s', '%s', '%s']);
        $registration_id = (int) $wpdb->insert_id;
        if ($ok) {
            foreach ($people as $p) {
                if (!$wpdb->insert(EKA_DB::table('participants'), ['registration_id' => $registration_id, 'name' => $p['name'], 'personal_number' => EKA_Core::encrypt($p['pnr'])], ['%d', '%s', '%s'])) {
                    EKA_Core::delete_registration($registration_id);
                    $ok = false;
                    break;
                }
            }
        }
        EKA_Core::unlock_session($course_id, $session);
        if (!$ok) self::fail('Anmälan kunde inte sparas. Försök igen.');

        $wants_newsletter = !empty($_POST['newsletter']);
        EKA_Core::subscribe($course_id, $email, $name, $wants_newsletter);
        do_action('eka_after_registration', $course_id, $reg, $wants_newsletter);
        EKA_Mailer::send_welcome($course_id, $reg, $people);
        EKA_Mailer::notify_responsible($course_id, $reg, $people);

        wp_safe_redirect(add_query_arg(['eka_thanks' => 1, 'ref' => $reg['payment_reference']], get_permalink($course_id)) . '#eka-tack');
        exit;
    }

    public static function handle_unsubscribe() {
        global $wpdb;
        $token = sanitize_key(wp_unslash($_GET['token'] ?? ''));
        $t = EKA_DB::table('subscribers');
        $row = $token ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE token=%s", $token)) : null;
        if (!$row) wp_die('Länken är ogiltig eller har redan använts.', 'Avsluta prenumeration', ['response' => 404]);
        $wpdb->update($t, ['status' => 'unsubscribed', 'newsletter' => 0, 'updated_at' => current_time('mysql')], ['id' => $row->id], ['%s', '%d', '%s'], ['%d']);
        wp_die(esc_html(sprintf('%s är nu borttagen från maillistan för %s.', $row->email, get_the_title($row->course_id))) . '<p><a href="' . esc_url(home_url('/')) . '">Till webbplatsen</a></p>', 'Prenumerationen är avslutad', ['response' => 200]);
    }

    /* ---------------- Tacksida med Swish ---------------- */

    public static function thanks_box($content) {
        if (!is_singular('eka_course') || empty($_GET['eka_thanks']) || !in_the_loop() || !is_main_query()) return $content;
        $ref = sanitize_text_field(wp_unslash($_GET['ref'] ?? ''));
        global $wpdb;
        $reg = $wpdb->get_row($wpdb->prepare('SELECT deposit_amount, total_amount FROM ' . EKA_DB::table('registrations') . ' WHERE payment_reference=%s AND course_id=%d', $ref, get_the_ID()));
        if (!$reg) return $content;
        $amount = (float) $reg->deposit_amount;
        $full = $amount >= (float) $reg->total_amount;
        $swish = (string) get_option('eka_swish_number');
        $digits = preg_replace('/\D/', '', $swish);

        $box = '<div class="eka-success" id="eka-tack"><h2>Tack för din anmälan!</h2>';
        if ($amount > 0 && $digits !== '') {
            $box .= '<p>Swisha <strong>' . esc_html(EKA_Core::money($amount)) . ' kr</strong> ' . ($full ? '(hela kursavgiften)' : 'i föranmälningsavgift') . ' till <strong>' . esc_html($swish) . '</strong>.</p>';
            $box .= '<p>Ange referensen <strong>' . esc_html($ref) . '</strong> som meddelande.</p>';
            $box .= '<div class="eka-swish"><a class="eka-button eka-swish-link" href="' . esc_url(self::swish_link($digits, $amount, $ref), ['swish']) . '">Öppna Swish</a>';
            $qr = self::swish_qr($digits, $amount, $ref);
            if ($qr) $box .= '<figure class="eka-swish-qr"><img src="data:image/png;base64,' . esc_attr($qr) . '" width="220" height="220" alt="QR-kod för Swish-betalning"><figcaption>Skanna med Swish-appen</figcaption></figure>';
            $box .= '</div>';
        }
        $box .= '<p>En bekräftelse har skickats via e-post.</p></div>';
        return $box . $content;
    }

    /** Länk som öppnar Swish-appen med mottagare, belopp och meddelande ifyllda. */
    private static function swish_link($number, $amount, $ref) {
        $data = ['version' => 1, 'payee' => ['value' => $number], 'amount' => ['value' => round($amount, 2)], 'message' => ['value' => $ref, 'editable' => false]];
        return 'swish://payment?data=' . rawurlencode(wp_json_encode($data));
    }

    /** Hämtar en förifylld QR-kod från Swish (cachas). Returnerar base64-PNG eller tom sträng. */
    private static function swish_qr($number, $amount, $ref) {
        $payload = ['format' => 'png', 'size' => 300, 'payee' => ['value' => $number, 'editable' => false], 'amount' => ['value' => round($amount, 2), 'editable' => false], 'message' => ['value' => $ref, 'editable' => false]];
        $cache_key = 'eka_qr_' . md5(wp_json_encode($payload));
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') return $cached;
        $res = wp_remote_post('https://mpc.getswish.net/qrg-swish/api/v1/prefilled', ['timeout' => 5, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload)]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return '';
        $png = wp_remote_retrieve_body($res);
        if (strpos($png, "\x89PNG") !== 0) return '';
        $b64 = base64_encode($png);
        set_transient($cache_key, $b64, MONTH_IN_SECONDS);
        return $b64;
    }
}
