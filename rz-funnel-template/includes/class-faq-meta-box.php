<?php
namespace RZFT;

defined('ABSPATH') || exit;

/**
 * Per-product FAQ accordion content (BLUEPRINT.md 4.5 structural reference),
 * editable from the normal product edit screen -- same content-stays-
 * editable-without-a-code-change principle the hero/benefit sections follow.
 * One textarea, one "Question | Answer" pair per line -- no repeater UI
 * dependency, keeps this dependency-free per CLAUDE.md hard rule #2.
 */
class FAQ_Meta_Box {

    const META_KEY    = '_rzft_faqs';
    const NONCE_FIELD  = 'rzft_faqs_nonce';
    const NONCE_ACTION = 'rzft_save_faqs';

    public function register(): void {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post_product', [$this, 'save']);
    }

    public function add_meta_box(): void {
        add_meta_box(
            'rzft_faqs',
            'Landing Page FAQ',
            [$this, 'render'],
            'product',
            'normal',
            'default'
        );
    }

    public function render(\WP_Post $post): void {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $faqs = self::get_faqs($post->ID);
        $lines = [];
        foreach ($faqs as $faq) {
            $lines[] = $faq['question'] . ' | ' . $faq['answer'];
        }
        ?>
        <p>One question per line, formatted as <code>Question | Answer</code>. Shown as an accordion near the bottom of this product's landing page. Leave empty to hide the FAQ section.</p>
        <textarea name="rzft_faqs_raw" rows="6" style="width:100%;" placeholder="Is this safe for sensitive skin? | Yes, dermatologically tested for daily use."><?php echo esc_textarea(implode("\n", $lines)); ?></textarea>
        <?php
    }

    public function save(int $post_id): void {
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $raw = isset($_POST['rzft_faqs_raw']) ? (string) wp_unslash($_POST['rzft_faqs_raw']) : '';
        $faqs = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }
            [$question, $answer] = array_map('trim', explode('|', $line, 2));
            if ($question === '' || $answer === '') {
                continue;
            }
            $faqs[] = [
                'question' => sanitize_text_field($question),
                'answer'   => sanitize_text_field($answer),
            ];
        }

        update_post_meta($post_id, self::META_KEY, $faqs);
    }

    /** @return array<int, array{question: string, answer: string}> */
    public static function get_faqs(int $product_id): array {
        $faqs = get_post_meta($product_id, self::META_KEY, true);
        return is_array($faqs) ? $faqs : [];
    }
}
