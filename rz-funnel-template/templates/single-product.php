<?php
/**
 * Landing-page single-product template (BLUEPRINT.md 4.5). Replaces
 * WooCommerce's default single-product.php for every product via the
 * `template_include` filter in Funnel_Template -- order form near the top,
 * benefit/FAQ content pulled from the product's own fields, cross-sell
 * before the footer. No before/after photos, guarantee language, or fake
 * scarcity counters -- deliberately not carried over from the old reference
 * page (see BLUEPRINT.md 4.5).
 */

defined('ABSPATH') || exit;

get_header();

while (have_posts()) :
    the_post();

    global $product;
    if (!($product instanceof \WC_Product)) {
        $product = wc_get_product(get_the_ID());
    }
    if (!$product) {
        continue;
    }

    $main_image_id  = $product->get_image_id();
    $gallery_ids    = $product->get_gallery_image_ids();
    $description    = $product->get_description();
    $short_desc     = $product->get_short_description();
    $faqs           = \RZFT\FAQ_Meta_Box::get_faqs($product->get_id());
    $related_ids    = function_exists('wc_get_related_products') ? wc_get_related_products($product->get_id(), 4) : [];
    ?>

    <main class="rzft-landing">

        <section class="rzft-hero">
            <div class="rzft-hero__gallery">
                <?php if ($main_image_id) : ?>
                    <?php echo wp_get_attachment_image($main_image_id, 'large', false, ['class' => 'rzft-hero__image']); ?>
                <?php else : ?>
                    <?php echo wc_placeholder_img('large', ['class' => 'rzft-hero__image']); ?>
                <?php endif; ?>

                <?php if (!empty($gallery_ids)) : ?>
                    <div class="rzft-hero__thumbs">
                        <?php foreach ($gallery_ids as $thumb_id) : ?>
                            <?php echo wp_get_attachment_image($thumb_id, 'medium', false, ['class' => 'rzft-hero__thumb', 'loading' => 'lazy']); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="rzft-hero__info">
                <h1 class="rzft-hero__title"><?php echo esc_html(get_the_title()); ?></h1>
                <div class="rzft-hero__price"><?php echo $product->get_price_html(); /* WC-sanitized HTML */ ?></div>
                <a href="#dp-order-now" class="rzft-cta">অর্ডার করুন</a>
            </div>
        </section>

        <section id="dp-order-now" class="rzft-order">
            <h2 class="rzft-order__title">অর্ডার করতে নিচের ফর্মটি পূরণ করুন</h2>

            <div id="rzft-form-message" class="rzft-form-message is-hidden" role="alert"></div>

            <form id="rzft-order-form" class="rzft-order__form" autocomplete="on">
                <input type="hidden" name="product_id" value="<?php echo esc_attr($product->get_id()); ?>">
                <input type="hidden" name="variation_id" value="0">
                <input type="hidden" name="billing_last_name" value="">
                <input type="hidden" name="billing_country" value="BD">
                <input type="hidden" name="rzog_cart_value" value="<?php echo esc_attr($product->get_price()); ?>">

                <label class="rzft-field">
                    <span>পুরো নাম</span>
                    <input type="text" name="billing_first_name" id="billing_first_name" required>
                </label>

                <label class="rzft-field">
                    <span>মোবাইল নাম্বার</span>
                    <input type="tel" name="billing_phone" id="billing_phone" inputmode="numeric" required>
                </label>

                <label class="rzft-field">
                    <span>সম্পূর্ণ ঠিকানা</span>
                    <textarea name="billing_address_1" id="billing_address_1" rows="2" required></textarea>
                </label>

                <label class="rzft-field rzft-field--qty">
                    <span>পরিমাণ</span>
                    <span class="rzft-qty">
                        <button type="button" class="rzft-qty__btn" data-rzft-qty="decrease" aria-label="Decrease quantity">&minus;</button>
                        <input type="number" name="quantity" id="rzft-qty-input" value="1" min="1" inputmode="numeric">
                        <button type="button" class="rzft-qty__btn" data-rzft-qty="increase" aria-label="Increase quantity">&plus;</button>
                    </span>
                </label>

                <p class="rzft-order__cod">পেমেন্ট পদ্ধতি: ক্যাশ অন ডেলিভারি (COD)</p>

                <button type="submit" class="rzft-cta rzft-order__submit">অর্ডার নিশ্চিত করুন</button>
            </form>
        </section>

        <?php if ($short_desc !== '' || $description !== '') : ?>
            <section class="rzft-benefits">
                <?php if ($short_desc !== '') : ?>
                    <div class="rzft-benefits__short"><?php echo wp_kses_post(wpautop($short_desc)); ?></div>
                <?php endif; ?>

                <a href="#dp-order-now" class="rzft-cta rzft-cta--ghost">এখনই অর্ডার করুন</a>

                <?php if ($description !== '') : ?>
                    <div class="rzft-benefits__full"><?php echo wp_kses_post(wpautop($description)); ?></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <div class="rzft-cta-band">
            <a href="#dp-order-now" class="rzft-cta">অর্ডার করুন</a>
        </div>

        <?php if (!empty($faqs)) : ?>
            <section class="rzft-faq">
                <h2 class="rzft-faq__title">সচরাচর জিজ্ঞাসিত প্রশ্ন</h2>
                <?php foreach ($faqs as $index => $faq) : ?>
                    <details class="rzft-faq__item">
                        <summary class="rzft-faq__question"><?php echo esc_html($faq['question']); ?></summary>
                        <div class="rzft-faq__answer"><?php echo esc_html($faq['answer']); ?></div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($related_ids)) : ?>
            <section class="rzft-related">
                <h2 class="rzft-related__title">আরও দেখুন</h2>
                <div class="rzft-related__grid">
                    <?php foreach ($related_ids as $related_id) :
                        $related = wc_get_product($related_id);
                        if (!$related || !$related->is_visible()) {
                            continue;
                        }
                        $related_image_id = $related->get_image_id();
                        ?>
                        <a href="<?php echo esc_url(get_permalink($related_id)); ?>" class="rzft-related__card">
                            <?php if ($related_image_id) : ?>
                                <?php echo wp_get_attachment_image($related_image_id, 'medium', false, ['loading' => 'lazy']); ?>
                            <?php else : ?>
                                <?php echo wc_placeholder_img('medium', ['loading' => 'lazy']); ?>
                            <?php endif; ?>
                            <span class="rzft-related__name"><?php echo esc_html($related->get_name()); ?></span>
                            <span class="rzft-related__price"><?php echo $related->get_price_html(); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <footer class="rzft-trust">
            <div class="rzft-trust__badges">
                <span>COD</span>
                <span>bKash</span>
                <span>Nagad</span>
            </div>
            <?php
            $whatsapp = get_option('rzog_contact_whatsapp', '');
            $phone    = get_option('rzog_contact_phone', '');
            if ($whatsapp || $phone) :
                ?>
                <div class="rzft-trust__contact">
                    <?php if ($whatsapp) : ?>
                        <a href="<?php echo esc_url($whatsapp); ?>">WhatsApp</a>
                    <?php endif; ?>
                    <?php if ($phone) : ?>
                        <a href="tel:<?php echo esc_attr($phone); ?>"><?php echo esc_html($phone); ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (function_exists('get_privacy_policy_url') && get_privacy_policy_url()) : ?>
                <a class="rzft-trust__policy" href="<?php echo esc_url(get_privacy_policy_url()); ?>">Privacy Policy</a>
            <?php endif; ?>
        </footer>

    </main>

    <div id="rzft-contact-modal" class="rzft-modal is-hidden">
        <div class="rzft-modal__box">
            <p>এই অর্ডারটি অনলাইনে নিশ্চিত করা যায়নি। অনুগ্রহ করে যোগাযোগ করুন:</p>
            <div class="rzft-modal__links"></div>
            <button type="button" class="rzft-modal__close">বন্ধ করুন</button>
        </div>
    </div>

<?php
endwhile;

get_footer();
