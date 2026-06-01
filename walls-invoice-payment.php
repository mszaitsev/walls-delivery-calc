<?php
/**
 * Plugin Name: Оплата по счету от ИП/ООО
 * Plugin URI: https://walls-shop.ru/
 * Description: Способ оплаты WooCommerce с проверкой ИНН через Dadata и оформлением заказа в статусе «Ожидает оплату».
 * Version: 1.1.53
 * Author: Михаил Зайцев
 * Text Domain: walls-invoice-payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/* === ВЕРСИЯ ПЛАГИНА === */
if (!defined('WALLS_INVOICE_PAYMENT_VERSION')) {
define('WALLS_INVOICE_PAYMENT_VERSION', '1.1.53');
}

define('WALLS_INVOICE_PAYMENT_GATEWAY_ID', 'invoice_ip_ooo');
define('WALLS_INVOICE_PAYMENT_NONCE_ACTION', 'walls_invoice_payment_dadata_nonce');

function walls_invoice_payment_plugins_loaded() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (class_exists('WC_Gateway_Walls_Invoice_Payment')) {
        return;
    }

    class WC_Gateway_Walls_Invoice_Payment extends WC_Payment_Gateway {
        public $dadata_token = '';
        public $thankyou_message = '';
        public $order_status_message = '';

        public function __construct() {
            $this->id                 = WALLS_INVOICE_PAYMENT_GATEWAY_ID;
            $this->icon               = '';
            $this->has_fields         = true;
            $this->method_title       = 'Оплата от ИП/ООО по счету';
            $this->method_description = 'Оформление заказа с проверкой ИНН через Dadata и последующей выставкой счета менеджером.';
            $this->supports           = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->title                = $this->get_option('title', 'Оплата от ИП/ООО по счету');
            $this->description          = $this->get_option('description', 'Укажите ИНН компании или ИП, подтвердите данные и оформите заказ.');
            $this->enabled              = $this->get_option('enabled', 'yes');
            $this->dadata_token         = trim((string) $this->get_option('dadata_token', ''));
            $this->thankyou_message     = $this->get_option('thankyou_message', 'Спасибо за заказ, в скором времени менеджер магазина свяжется с вами и предоставит счет для оплаты.');
            $this->order_status_message = $this->get_option('order_status_message', 'Ожидает выставления счета менеджером.');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Включить/выключить',
                    'type'    => 'checkbox',
                    'label'   => 'Включить способ оплаты',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'    => 'Название',
                    'type'     => 'text',
                    'default'  => 'Оплата от ИП/ООО по счету',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title'    => 'Описание',
                    'type'     => 'textarea',
                    'default'  => 'Укажите ИНН компании или ИП, подтвердите данные и оформите заказ.',
                    'desc_tip' => true,
                ),
                'dadata_token' => array(
                    'title'       => 'Dadata API Token',
                    'type'        => 'password',
                    'default'     => '',
                    'description' => 'Серверный токен Dadata для метода findById/party.',
                    'desc_tip'    => true,
                ),
                'thankyou_message' => array(
                    'title'    => 'Сообщение после оформления',
                    'type'     => 'textarea',
                    'default'  => 'Спасибо за заказ, в скором времени менеджер магазина свяжется с вами и предоставит счет для оплаты.',
                    'desc_tip' => true,
                ),
                'order_status_message' => array(
                    'title'    => 'Комментарий к статусу заказа',
                    'type'     => 'text',
                    'default'  => 'Ожидает выставления счета менеджером.',
                    'desc_tip' => true,
                ),
            );
        }

        public function payment_fields() {
            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }

            $posted_inn      = isset($_POST['invoice_company_inn']) ? wc_clean(wp_unslash($_POST['invoice_company_inn'])) : '';
            $posted_name     = isset($_POST['invoice_company_name']) ? wc_clean(wp_unslash($_POST['invoice_company_name'])) : '';
            $posted_address  = isset($_POST['invoice_company_address']) ? wc_clean(wp_unslash($_POST['invoice_company_address'])) : '';
            $posted_type     = isset($_POST['invoice_company_type']) ? wc_clean(wp_unslash($_POST['invoice_company_type'])) : '';
            $posted_verified = isset($_POST['invoice_company_verified']) ? wc_clean(wp_unslash($_POST['invoice_company_verified'])) : '0';
            $posted_city     = isset($_POST['invoice_company_city_hint']) ? wc_clean(wp_unslash($_POST['invoice_company_city_hint'])) : '';
            ?>
            <div id="walls-invoice-payment-fields" class="walls-invoice-payment-fields" data-gateway-id="<?php echo esc_attr($this->id); ?>">

                <p class="form-row form-row-wide walls-inn-label-row">
                    <label for="invoice_company_inn">
                        ИНН <span class="required">*</span>
                        <span class="walls-invoice-payment-hint">(юрлицо — 10 цифр, ИП — 12 цифр)</span>
                    </label>
                </p>

                <div class="walls-invoice-payment-inn-line">
                    <p class="form-row walls-invoice-payment-inn-row">
                        <input
                            type="text"
                            id="invoice_company_inn"
                            name="invoice_company_inn"
                            inputmode="numeric"
                            autocomplete="off"
                            value="<?php echo esc_attr($posted_inn); ?>"
                            maxlength="12"
                            placeholder="10 или 12 цифр ИНН"
                            pattern="[0-9]*"
                        />
                    </p>

                    <p class="form-row walls-invoice-payment-check-row">
                        <button type="button" class="button" id="invoice_company_check_button">Проверить</button>
                    </p>
                </div>

                <div id="invoice_company_message" class="walls-invoice-payment-message" style="display:none;"></div>
                <div id="invoice_company_error" class="walls-invoice-payment-error" style="display:none;"></div>

                <p class="form-row form-row-wide">
                    <label for="invoice_company_name">Наименование</label>
                    <input
                        type="text"
                        id="invoice_company_name"
                        name="invoice_company_name"
                        value="<?php echo esc_attr($posted_name); ?>"
                        readonly
                    />
                </p>

                <p class="form-row form-row-wide" id="invoice_company_address_row" style="display:none;">
                    <label for="invoice_company_address">Юридический адрес <span class="required">*</span></label>
                    <textarea
                        id="invoice_company_address"
                        name="invoice_company_address"
                        rows="2"
                        placeholder="Укажите юридический адрес"
                    ><?php echo esc_textarea($posted_address); ?></textarea>
                    <small class="description" id="invoice_company_address_help"></small>
                </p>

                <?php
                    $posted_edo          = isset($_POST['invoice_company_edo']) ? wc_clean(wp_unslash($_POST['invoice_company_edo'])) : '';
                    $posted_edo_operator = isset($_POST['invoice_company_edo_operator']) ? wc_clean(wp_unslash($_POST['invoice_company_edo_operator'])) : '';
                ?>
                
                <p class="form-row form-row-wide" id="invoice_company_edo_wrap" style="display:none;">
                    <span class="walls-invoice-payment-edo-line">
                        <label class="checkbox" for="invoice_company_edo" id="invoice_company_edo_row">
                            <input
                                type="checkbox"
                                id="invoice_company_edo"
                                name="invoice_company_edo"
                                value="1"
                                <?php checked($posted_edo, '1'); ?>
                            />
                            <span>Есть ЭДО</span>
                        </label>
                
                        <span id="invoice_company_edo_operator_row" style="display:none;">
                            <input
                                type="text"
                                id="invoice_company_edo_operator"
                                name="invoice_company_edo_operator"
                                value="<?php echo esc_attr($posted_edo_operator); ?>"
                                maxlength="30"
                                placeholder="Название оператора"
                                autocomplete="off"
                            />
                        </span>
                    </span>
                </p>

                <input type="hidden" id="invoice_company_type" name="invoice_company_type" value="<?php echo esc_attr($posted_type); ?>" />
                <input type="hidden" id="invoice_company_verified" name="invoice_company_verified" value="<?php echo esc_attr($posted_verified); ?>" />
                <input type="hidden" id="invoice_company_city_hint" name="invoice_company_city_hint" value="<?php echo esc_attr($posted_city); ?>" />
            </div>
            <?php
        }

        public function validate_fields() {
            if (!isset($_POST['payment_method']) || $this->id !== wc_clean(wp_unslash($_POST['payment_method']))) {
                return true;
            }

            $inn      = isset($_POST['invoice_company_inn']) ? preg_replace('/\D+/', '', wp_unslash($_POST['invoice_company_inn'])) : '';
            $name     = isset($_POST['invoice_company_name']) ? wc_clean(wp_unslash($_POST['invoice_company_name'])) : '';
            $address  = isset($_POST['invoice_company_address']) ? trim((string) wc_clean(wp_unslash($_POST['invoice_company_address']))) : '';
            $type     = isset($_POST['invoice_company_type']) ? wc_clean(wp_unslash($_POST['invoice_company_type'])) : '';
            $verified = isset($_POST['invoice_company_verified']) ? wc_clean(wp_unslash($_POST['invoice_company_verified'])) : '0';

            if ($inn === '') {
                wc_add_notice('Укажите ИНН компании или ИП.', 'error');
                return false;
            }

            if (!in_array(strlen($inn), array(10, 12), true)) {
                wc_add_notice('ИНН должен содержать 10 цифр для юрлица или 12 цифр для ИП.', 'error');
                return false;
            }

            if ($verified !== '1') {
                wc_add_notice('Проверьте ИНН кнопкой «Проверить» перед оформлением заказа.', 'error');
                return false;
            }

            if ($type !== 'LEGAL' && $type !== 'INDIVIDUAL') {
                wc_add_notice('Не удалось определить тип плательщика по ИНН.', 'error');
                return false;
            }

            if ($name === '') {
                wc_add_notice('Не удалось получить наименование. Проверьте ИНН повторно.', 'error');
                return false;
            }

            if ($type === 'LEGAL' && $address === '') {
                wc_add_notice('Не удалось получить юридический адрес организации.', 'error');
                return false;
            }

            if ($type === 'INDIVIDUAL' && mb_strlen($address) < 10) {
                wc_add_notice('Для ИП необходимо вручную указать юридический адрес (минимум 10 символов).', 'error');
                return false;
            }

            return true;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            if (!$order) {
                wc_add_notice('Не удалось создать заказ. Попробуйте ещё раз.', 'error');
                return array('result' => 'failure');
            }

            $inn          = isset($_POST['invoice_company_inn']) ? preg_replace('/\D+/', '', wp_unslash($_POST['invoice_company_inn'])) : '';
            $name         = isset($_POST['invoice_company_name']) ? wc_clean(wp_unslash($_POST['invoice_company_name'])) : '';
            $address      = isset($_POST['invoice_company_address']) ? trim((string) wc_clean(wp_unslash($_POST['invoice_company_address']))) : '';
            $type         = isset($_POST['invoice_company_type']) ? wc_clean(wp_unslash($_POST['invoice_company_type'])) : '';
            $city         = isset($_POST['invoice_company_city_hint']) ? wc_clean(wp_unslash($_POST['invoice_company_city_hint'])) : '';
            $edo          = isset($_POST['invoice_company_edo']) ? wc_clean(wp_unslash($_POST['invoice_company_edo'])) : '';
            $edo_operator = isset($_POST['invoice_company_edo_operator']) ? wc_clean(wp_unslash($_POST['invoice_company_edo_operator'])) : '';
            $edo_operator = mb_substr(trim($edo_operator), 0, 30);

            $order->update_meta_data('_invoice_company_inn', $inn);
            $order->update_meta_data('_invoice_company_name', $name);
            $order->update_meta_data('_invoice_company_address', $address);
            $order->update_meta_data('_invoice_company_type', $type);
            $order->update_meta_data('_invoice_company_edo', $edo === '1' ? '1' : '0');
            if ($edo_operator !== '') {
                $order->update_meta_data('_invoice_company_edo_operator', $edo_operator);
            }

            if ($city !== '') {
                $order->update_meta_data('_invoice_company_city_hint', $city);
            }

            $existing_note = (string) $order->get_customer_note();
            $extra_note = array(
                '---',
                'Данные для оплаты по счету:',
                'Тип: ' . ($type === 'INDIVIDUAL' ? 'ИП' : 'Юрлицо'),
                'ИНН: ' . $inn,
                'Наименование: ' . $name,
                'Юридический адрес: ' . $address,
            );

            if ($edo === '1') {
                if ($edo_operator !== '') {
                    $extra_note[] = sprintf('Есть ЭДО, оператор %s', $edo_operator);
                } else {
                    $extra_note[] = 'Есть ЭДО';
                }
            }

            if ($city !== '' && $type === 'INDIVIDUAL') {
                $extra_note[] = 'Определился город: ' . $city;
            }

            $final_note = trim($existing_note);
            if ($final_note !== '') {
                $final_note .= "\n\n" . implode("\n", $extra_note);
            } else {
                $final_note = implode("\n", $extra_note);
            }

            $order->set_customer_note($final_note);
            $order->update_status('pending', $this->order_status_message);
            $order->save();

            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        public function thankyou_page($order_id) {
            if ($this->thankyou_message) {
                echo '<p>' . esc_html($this->thankyou_message) . '</p>';
            }
        }

        public function email_instructions($order, $sent_to_admin, $plain_text) {
            if (!$order instanceof WC_Order) {
                return;
            }

            if ($order->get_payment_method() !== $this->id) {
                return;
            }

            if ($sent_to_admin) {
                return;
            }

            if ($order->has_status(array('pending', 'on-hold'))) {
                if ($plain_text) {
                    echo "\n" . wp_strip_all_tags($this->thankyou_message) . "\n";
                } else {
                    echo '<p>' . esc_html($this->thankyou_message) . '</p>';
                }
            }
        }
    }
}
add_action('plugins_loaded', 'walls_invoice_payment_plugins_loaded', 20);

function walls_invoice_payment_register_gateway($gateways) {
    if (class_exists('WC_Gateway_Walls_Invoice_Payment')) {
        $gateways[] = 'WC_Gateway_Walls_Invoice_Payment';
    }
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'walls_invoice_payment_register_gateway');

function walls_invoice_payment_force_gateway_visible($available_gateways) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return $available_gateways;
    }

    if (!function_exists('is_checkout') || !is_checkout()) {
        return $available_gateways;
    }

    if (isset($available_gateways[WALLS_INVOICE_PAYMENT_GATEWAY_ID])) {
        return $available_gateways;
    }

    $gateway_settings = get_option('woocommerce_' . WALLS_INVOICE_PAYMENT_GATEWAY_ID . '_settings', array());
    $gateway_enabled  = isset($gateway_settings['enabled']) ? $gateway_settings['enabled'] : 'no';

    if ($gateway_enabled !== 'yes') {
        return $available_gateways;
    }

    if (class_exists('WC_Gateway_Walls_Invoice_Payment')) {
        $available_gateways[WALLS_INVOICE_PAYMENT_GATEWAY_ID] = new WC_Gateway_Walls_Invoice_Payment();
    }

    return $available_gateways;
}
add_filter('woocommerce_available_payment_gateways', 'walls_invoice_payment_force_gateway_visible', 9999);

function walls_invoice_payment_render_checkout_nonce() {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    wp_nonce_field(WALLS_INVOICE_PAYMENT_NONCE_ACTION, 'walls_invoice_payment_nonce');
}
add_action('woocommerce_review_order_before_submit', 'walls_invoice_payment_render_checkout_nonce');

function walls_invoice_payment_validate_checkout_nonce() {
    $payment_method = isset($_POST['payment_method']) ? wc_clean(wp_unslash($_POST['payment_method'])) : '';

    if ($payment_method !== WALLS_INVOICE_PAYMENT_GATEWAY_ID) {
        return;
    }

    $nonce = isset($_POST['walls_invoice_payment_nonce']) ? wp_unslash($_POST['walls_invoice_payment_nonce']) : '';
    if (!$nonce || !wp_verify_nonce($nonce, WALLS_INVOICE_PAYMENT_NONCE_ACTION)) {
        wc_add_notice('Ошибка проверки формы. Обновите страницу и попробуйте ещё раз.', 'error');
    }
}
add_action('woocommerce_checkout_process', 'walls_invoice_payment_validate_checkout_nonce');

function walls_invoice_payment_enqueue_assets() {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    if (!class_exists('WooCommerce')) {
        return;
    }

    $gateway_settings = get_option('woocommerce_' . WALLS_INVOICE_PAYMENT_GATEWAY_ID . '_settings', array());
    $gateway_enabled  = isset($gateway_settings['enabled']) ? $gateway_settings['enabled'] : 'no';

    if ($gateway_enabled !== 'yes') {
        return;
    }

    wp_register_script('walls-invoice-payment', false, array('jquery', 'wc-checkout'), WALLS_INVOICE_PAYMENT_VERSION, true);
    wp_enqueue_script('walls-invoice-payment');

    wp_localize_script('walls-invoice-payment', 'WallsInvoicePayment', array(
        'ajaxUrl'   => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce(WALLS_INVOICE_PAYMENT_NONCE_ACTION),
        'gatewayId' => WALLS_INVOICE_PAYMENT_GATEWAY_ID,
        'messages'  => array(
            'invalidInn'      => 'ИНН должен содержать 10 цифр для юрлица или 12 цифр для ИП.',
            'notFound'        => 'Компания не найдена, проверьте введенный ИНН.',
            'checking'        => 'Проверяем ИНН...',
            'lookupError'     => 'Не удается найти данные по ИНН.',
            'legalFound'      => 'Данные организации успешно получены. Можно оформлять заказ!',
            'ipFound'         => 'Данные ИП найдены. Юридический адрес нужно указать вручную, затем оформить заказ.',
            'manualIpAddress' => 'Для ИП укажите юридический адрес вручную (минимум 10 символов).',
            'legalAddressAuto'=> 'Юридический адрес получен автоматически.',
            'placeOrderText'   => 'Оплатить заказ',
            'invoiceText'      => 'Оформить заказ',
        ),
    ));

$inline_js = <<<'JS'
(function($){
    function digitsOnly(value) {
        return (value || '').replace(/\D+/g, '');
    }

    function isGatewaySelected() {
        return $('input[name="payment_method"]:checked').val() === WallsInvoicePayment.gatewayId;
    }

    function getAddressValue() {
        return $.trim($('#invoice_company_address').val() || '');
    }

    function isAddressReady() {
        var type = $('#invoice_company_type').val();
        var address = getAddressValue();

        if (type === 'LEGAL') {
            return address.length > 0;
        }

        if (type === 'INDIVIDUAL') {
            return address.length >= 10;
        }

        return false;
    }

    function updatePlaceOrderText() {
        var $button = $('#place_order');
        if (!$button.length) {
            return;
        }

        if (isGatewaySelected()) {
            $button.text(WallsInvoicePayment.messages.invoiceText);
        } else {
            $button.text(WallsInvoicePayment.messages.placeOrderText);
        }
    }

    function updatePlaceOrderState() {
        var $button = $('#place_order');

        if (!$button.length) {
            return;
        }

        updatePlaceOrderText();

        if (!isGatewaySelected()) {
            $button.prop('disabled', false).removeClass('walls-invoice-payment-disabled');
            return;
        }

        var inn = digitsOnly($('#invoice_company_inn').val());
        var verified = $('#invoice_company_verified').val() === '1';
        var type = $('#invoice_company_type').val();
        var ready = false;

        if (verified && (inn.length === 10 || inn.length === 12)) {
            if (type === 'LEGAL' || type === 'INDIVIDUAL') {
                ready = isAddressReady();
            }
        }

        $button.prop('disabled', !ready).toggleClass('walls-invoice-payment-disabled', !ready);
    }

    function setAddressMode(companyType) {
        var $address = $('#invoice_company_address');

        if (companyType === 'LEGAL') {
            $address
                .prop('readonly', true)
                .addClass('walls-invoice-payment-readonly');
            $('#invoice_company_address_help').text(WallsInvoicePayment.messages.legalAddressAuto);
        } else if (companyType === 'INDIVIDUAL') {
            $address
                .prop('readonly', false)
                .removeClass('walls-invoice-payment-readonly');
            $('#invoice_company_address_help').text(WallsInvoicePayment.messages.manualIpAddress);
        } else {
            $address
                .prop('readonly', false)
                .removeClass('walls-invoice-payment-readonly');
            $('#invoice_company_address_help').text('');
        }
    }

    function resetLookupState() {
        $('#invoice_company_verified').val('0');
        $('#invoice_company_type').val('');
        $('#invoice_company_name').val('');
        $('#invoice_company_city_hint').val('');
        $('#invoice_company_address').val('');
        $('#invoice_company_message').hide().text('');
        $('#invoice_company_error').hide().text('');
        $('#invoice_company_address_row').hide();
        $('#invoice_company_edo_wrap').hide();
        $('#invoice_company_edo').prop('checked', false);
        $('#invoice_company_edo_operator').val('');
        $('#invoice_company_edo_operator_row').hide();
        setAddressMode('');
        updatePlaceOrderState();
    }

    function showMessage(text) {
        $('#invoice_company_error').hide().text('');
        $('#invoice_company_message').text(text).show();
    }

    function showError(text) {
        $('#invoice_company_message').hide().text('');
        $('#invoice_company_error').text(text).show();
    }

    function handleLookupSuccess(response) {
        if (!response || !response.success || !response.data) {
            showError(WallsInvoicePayment.messages.lookupError);
            updatePlaceOrderState();
            return;
        }

        var data = response.data;
        $('#invoice_company_verified').val('1');
        $('#invoice_company_type').val(data.company_type);
        $('#invoice_company_name').val(data.company_name || '');
        $('#invoice_company_city_hint').val(data.city_hint || '');

        $('#invoice_company_address_row').show();
        setAddressMode(data.company_type);

        if (data.company_type === 'LEGAL') {
            $('#invoice_company_address').val(data.address || '');
            showMessage(WallsInvoicePayment.messages.legalFound);

            if (!data.address) {
                $('#invoice_company_verified').val('0');
                showError('Юридический адрес не найден. Проверьте ИНН повторно.');
            }
        } else if (data.company_type === 'INDIVIDUAL') {
            $('#invoice_company_address').val('');
            showMessage(WallsInvoicePayment.messages.ipFound);
        }

        updatePlaceOrderState();
        updateEdoState();
    }

    function updateEdoState() {
        var verified = $('#invoice_company_verified').val() === '1';
        var checked = $('#invoice_company_edo').is(':checked');
    
        if (!verified) {
            $('#invoice_company_edo_wrap').hide();
            $('#invoice_company_edo').prop('checked', false);
            $('#invoice_company_edo_operator').val('');
            $('#invoice_company_edo_operator_row').hide();
            return;
        }
    
        $('#invoice_company_edo_wrap').show();
    
        if (checked) {
            $('#invoice_company_edo_operator_row').show();
        } else {
            $('#invoice_company_edo_operator_row').hide();
            $('#invoice_company_edo_operator').val('');
        }
    }

    $(document.body).on('change', 'input[name="payment_method"]', function() {
        if (isGatewaySelected()) {
            resetLookupState();
        } else {
            updatePlaceOrderState();
        }
    });

    $(document).on('input', '#invoice_company_inn', function() {
        var cleaned = digitsOnly($(this).val()).substring(0, 12);
        $(this).val(cleaned);
        resetLookupState();
    });

    $(document).on('input change', '#invoice_company_address', function() {
        updatePlaceOrderState();
    });

    $(document).on('change', '#invoice_company_edo', function() {
        updateEdoState();
    });
    
    $(document).on('input', '#invoice_company_edo_operator', function() {
        var val = $(this).val() || '';
        if (val.length > 30) {
            $(this).val(val.substring(0, 30));
        }
    });

    $(document).on('click', '#invoice_company_check_button', function(e) {
        e.preventDefault();

        if (!isGatewaySelected()) {
            return;
        }

        var inn = digitsOnly($('#invoice_company_inn').val()).substring(0, 12);
        $('#invoice_company_inn').val(inn);
        resetLookupState();

        if (!(inn.length === 10 || inn.length === 12)) {
            showError(WallsInvoicePayment.messages.invalidInn);
            updatePlaceOrderState();
            return;
        }

        showMessage(WallsInvoicePayment.messages.checking);

        $.ajax({
            url: WallsInvoicePayment.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'walls_invoice_payment_dadata_lookup',
                nonce: WallsInvoicePayment.nonce,
                inn: inn
            }
        }).done(function(response) {
            if (!response || !response.success) {
                var message = response && response.data && response.data.message ? response.data.message : WallsInvoicePayment.messages.lookupError;
                showError(message);
                updatePlaceOrderState();
                return;
            }
            handleLookupSuccess(response);
        }).fail(function() {
            showError(WallsInvoicePayment.messages.lookupError);
            updatePlaceOrderState();
        });
    });

    $(document.body).on('updated_checkout', function() {
        updatePlaceOrderState();
    });

    $(function(){
        updatePlaceOrderState();
        updateEdoState();
    });
})(jQuery);
JS;

    wp_add_inline_script('walls-invoice-payment', $inline_js);

$inline_css = <<<'CSS'
/* =========================
   CHECKOUT — ОБЩАЯ БАЗА
   ========================= */

.woocommerce-checkout #payment,
.woocommerce-checkout .woocommerce-checkout-review-order-table {
    background: transparent;
}

/* =========================
   CHECKOUT — ВЕРХНЯЯ ЧАСТЬ
   Данные покупателя + комментарии
   ========================= */

/* Одна колонка только для customer_details */
.woocommerce-checkout #customer_details,
.woocommerce-checkout #customer_details .col2-set,
.woocommerce-checkout #customer_details .col-1,
.woocommerce-checkout #customer_details .col-2 {
    float: none !important;
    width: 100% !important;
    max-width: 100% !important;
    clear: both !important;
    box-sizing: border-box;
}

/* Карточки секций */
.woocommerce-checkout #customer_details .woocommerce-billing-fields,
.woocommerce-checkout #customer_details .woocommerce-additional-fields {
    background: #ffffff;
    border: 1px solid #e3e3e3;
    border-radius: 16px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    padding: 26px 28px;
    margin: 0 0 24px;
}

/* Заголовки */
.woocommerce-checkout #customer_details .woocommerce-billing-fields > h3,
.woocommerce-checkout #customer_details .woocommerce-additional-fields > h3,
.woocommerce-checkout #customer_details h3 {
    font-size: 2rem;
    font-weight: 300;
    line-height: 1.2;
    margin: 0 0 20px;
    color: #3b3b3b;
}

/* Базовая сетка полей */
.woocommerce-checkout #customer_details .form-row {
    margin: 0 0 18px;
    padding: 0;
    box-sizing: border-box;
}

.woocommerce-checkout #customer_details .form-row label {
    display: block;
    margin: 0 0 8px;
    font-weight: 400;
    color: #6a6a6a;
}

.woocommerce-checkout #customer_details .form-row .required {
    color: #e5532f;
}

/* Поля */
.woocommerce-checkout #customer_details input[type="text"],
.woocommerce-checkout #customer_details input[type="email"],
.woocommerce-checkout #customer_details input[type="tel"],
.woocommerce-checkout #customer_details input[type="number"],
.woocommerce-checkout #customer_details input[type="password"],
.woocommerce-checkout #customer_details textarea,
.woocommerce-checkout #customer_details select,
.woocommerce-checkout #customer_details .select2-container--default .select2-selection--single {
    width: 100%;
    min-height: 50px;
    border: 1px solid #d5d5d5;
    border-radius: 10px;
    background: #ffffff;
    color: #2f2f2f;
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.woocommerce-checkout #customer_details input[type="text"],
.woocommerce-checkout #customer_details input[type="email"],
.woocommerce-checkout #customer_details input[type="tel"],
.woocommerce-checkout #customer_details input[type="number"],
.woocommerce-checkout #customer_details input[type="password"],
.woocommerce-checkout #customer_details textarea,
.woocommerce-checkout #customer_details select {
    padding: 12px 14px;
}

.woocommerce-checkout #customer_details textarea {
    min-height: 120px;
    resize: vertical;
}

.woocommerce-checkout #customer_details input[type="text"]:hover,
.woocommerce-checkout #customer_details input[type="email"]:hover,
.woocommerce-checkout #customer_details input[type="tel"]:hover,
.woocommerce-checkout #customer_details input[type="number"]:hover,
.woocommerce-checkout #customer_details input[type="password"]:hover,
.woocommerce-checkout #customer_details textarea:hover,
.woocommerce-checkout #customer_details select:hover,
.woocommerce-checkout #customer_details .select2-container--default .select2-selection--single:hover {
    border-color: #c0c0c0;
}

.woocommerce-checkout #customer_details input[type="text"]:focus,
.woocommerce-checkout #customer_details input[type="email"]:focus,
.woocommerce-checkout #customer_details input[type="tel"]:focus,
.woocommerce-checkout #customer_details input[type="number"]:focus,
.woocommerce-checkout #customer_details input[type="password"]:focus,
.woocommerce-checkout #customer_details textarea:focus,
.woocommerce-checkout #customer_details select:focus,
.woocommerce-checkout #customer_details .select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #88a08a;
    box-shadow: 0 0 0 2px rgba(63,95,67,0.10);
    outline: none;
}

/* Select2 */
.woocommerce-checkout #customer_details .select2-container--default .select2-selection--single {
    padding: 0 40px 0 14px;
    display: flex;
    align-items: center;
}

.woocommerce-checkout #customer_details .select2-container--default .select2-selection--single .select2-selection__rendered {
    padding: 0 !important;
    line-height: normal !important;
    color: #2f2f2f;
}

.woocommerce-checkout #customer_details .select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 100%;
    right: 10px;
}

/* Вспомогательные тексты */
.woocommerce-checkout #customer_details .description,
.woocommerce-checkout #customer_details .woocommerce-input-wrapper + span.description,
.woocommerce-checkout #customer_details .form-row small,
.woocommerce-checkout #customer_details .optional {
    color: #777;
}

/* Ошибки/валидность */
.woocommerce-checkout #customer_details .woocommerce-invalid input,
.woocommerce-checkout #customer_details .woocommerce-invalid textarea,
.woocommerce-checkout #customer_details .woocommerce-invalid select {
    border-color: #d76d6d !important;
}

.woocommerce-checkout #customer_details .woocommerce-validated input,
.woocommerce-checkout #customer_details .woocommerce-validated textarea,
.woocommerce-checkout #customer_details .woocommerce-validated select {
    border-color: #8baa8f !important;
}

/* Текстовые пояснения в блоке комментария */
.woocommerce-checkout #customer_details .woocommerce-additional-fields p {
    color: #666;
}

.woocommerce-checkout #customer_details .woocommerce-additional-fields ul,
.woocommerce-checkout #customer_details .woocommerce-additional-fields ol {
    margin: 8px 0 14px 20px;
    color: #666;
}

/* =========================
   CHECKOUT — ШАГ "ВАШ ЗАКАЗ" И НИЖЕ
   ========================= */

/* Базовая ширина review */
.woocommerce .col2-set,
.woocommerce-page .col2-set,
.woocommerce-checkout #customer_details + #wc_checkout_add_ons,
.woocommerce-checkout #order_review,
.woocommerce-checkout #order_review_heading {
    float: none !important;
    width: 100% !important;
    max-width: 100% !important;
    clear: both !important;
    box-sizing: border-box !important;
}

.woocommerce-checkout #order_review,
.woocommerce-checkout #order_review_heading,
.woocommerce-checkout .woocommerce-checkout-review-order-table {
    margin-left: 0 !important;
    margin-right: 0 !important;
}

/* Заголовок "Ваш заказ" */
.woocommerce-checkout #order_review_heading {
    margin: 0 0 14px;
    font-size: 2rem;
    font-weight: 300;
    line-height: 1.2;
    color: #3b3b3b;
}

/* Контейнер review */
.woocommerce-checkout #order_review {
    background: transparent;
    border: 0;
    padding: 0;
    margin: 0 0 24px;
    box-shadow: none;
    overflow: visible;
}

/* Таблица review как колонка */
.woocommerce-checkout-review-order-table,
.woocommerce-checkout-review-order-table tfoot {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.woocommerce-checkout-review-order-table thead,
.woocommerce-checkout-review-order-table tbody {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.woocommerce-checkout-review-order-table thead tr,
.woocommerce-checkout-review-order-table tbody tr,
.woocommerce-checkout-review-order-table tfoot tr {
    display: flex;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.woocommerce-checkout-review-order-table thead tr > th,
.woocommerce-checkout-review-order-table tbody tr > th,
.woocommerce-checkout-review-order-table tbody tr > td,
.woocommerce-checkout-review-order-table tfoot tr > th,
.woocommerce-checkout-review-order-table tfoot tr > td {
    width: unset;
    max-width: unset;
    flex-grow: 1;
    box-sizing: border-box;
}

/* Карточка товаров */
.woocommerce-checkout-review-order-table thead {
    background: #ffffff;
    border: 1px solid #e3e3e3;
    border-bottom: 0;
    border-radius: 16px 16px 0 0;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    overflow: hidden;
}

.woocommerce-checkout-review-order-table thead th {
    background: #fafafa;
    color: #666;
    font-weight: 600;
    padding: 18px 20px;
    border-bottom: 1px solid #ececec;
}

.woocommerce-checkout-review-order-table tbody {
    background: #ffffff;
    border-left: 1px solid #e3e3e3;
    border-right: 1px solid #e3e3e3;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}

.woocommerce-checkout-review-order-table tbody tr > th,
.woocommerce-checkout-review-order-table tbody tr > td {
    padding: 18px 20px;
    border-bottom: 1px solid #efefef;
    vertical-align: top;
}

.woocommerce-checkout-review-order-table tbody tr:last-child > th,
.woocommerce-checkout-review-order-table tbody tr:last-child > td {
    border-bottom: 0;
}

.woocommerce-checkout-review-order-table tr.cart-subtotal {
    background: #fcfcfc;
    border-left: 1px solid #e3e3e3;
    border-right: 1px solid #e3e3e3;
    border-bottom: 1px solid #e3e3e3;
    border-radius: 0 0 16px 16px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    overflow: hidden;
    margin-bottom: 24px;
}

.woocommerce-checkout-review-order-table tr.cart-subtotal > th,
.woocommerce-checkout-review-order-table tr.cart-subtotal > td {
    padding: 18px 20px;
}

.woocommerce-checkout-review-order-table .product-name {
    text-align: left;
    color: #555;
    overflow-wrap: anywhere;
    word-break: normal;
}

.woocommerce-checkout-review-order-table .product-total,
.woocommerce-checkout-review-order-table td:last-child {
    text-align: right;
    white-space: nowrap;
    color: #555;
}

/* Статический блок доставки */
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping.just_label {
    display: block;
    width: 100%;
    max-width: 100%;
    margin: 0 0 24px 0;
    box-sizing: border-box;
}

.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping.just_label > td {
    display: block;
    width: 100% !important;
    max-width: 100% !important;
    padding: 0;
    margin: 0;
    box-sizing: border-box;
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: normal;
}

.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping.just_label h3 {
    margin: 0 0 16px 0;
    font-size: 1.55rem;
    line-height: 1.2;
    font-weight: 400;
    color: #4c4c4c;
}

.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping.just_label td > * {
    max-width: 100%;
    box-sizing: border-box;
}

.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping.just_label td a {
    overflow-wrap: anywhere;
    word-break: break-word;
}

/* Блок выбора доставки */
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) {
    display: block;
    width: 100%;
    max-width: 100%;
    margin: 0 0 24px 0;
    box-sizing: border-box;
}

.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > th,
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > td {
    display: block;
    width: 100% !important;
    max-width: 100% !important;
    padding: 0;
    margin: 0;
    box-sizing: border-box;
}

.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > th {
    margin: 0 0 12px 0;
    font-size: 1.55rem;
    line-height: 1.2;
    font-weight: 400;
    color: #4c4c4c;
}

.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > td {
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: normal;
}

/* Статический текст в td перед списком доставок */
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > td > font,
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > td > b,
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > td > a,
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > td > u,
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > td > span,
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > td > small {
    max-width: 100%;
    box-sizing: border-box;
}

.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > td a {
    overflow-wrap: anywhere;
    word-break: break-word;
}

/* Список способов доставки */
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) ul#shipping_method,
.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) ul.woocommerce-shipping-methods,
.woocommerce-checkout ul#shipping_method {
    display: block;
    width: 100%;
    max-width: 100%;
    margin: 14px 0 8px 0;
    padding: 0;
    list-style: none;
    box-sizing: border-box;
}

/* Карточка доставки */
.woocommerce-checkout ul#shipping_method > li {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    margin: 0 0 14px 0;
    padding: 0;
    list-style: none;
    border: 1px solid #dddddd;
    border-radius: 14px;
    background: #ffffff;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    box-sizing: border-box;
}

.woocommerce-checkout ul#shipping_method > li:hover {
    border-color: #cfcfcf;
    box-shadow: 0 4px 16px rgba(0,0,0,0.05);
}

/* Нативную radio скрываем */
.woocommerce-checkout ul#shipping_method > li > input[type="radio"] {
    position: absolute !important;
    left: 0 !important;
    top: 0 !important;
    width: 1px !important;
    height: 1px !important;
    margin: 0 !important;
    padding: 0 !important;
    opacity: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
    clip: rect(0 0 0 0) !important;
    clip-path: inset(50%) !important;
    white-space: nowrap !important;
    appearance: none !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
}

/* Верхняя строка карточки */
.woocommerce-checkout ul#shipping_method > li > label {
    display: block;
    width: 100%;
    margin: 0;
    padding: 22px 150px 22px 62px;
    position: relative;
    cursor: pointer;
    background: #ffffff;
    color: #2f2f2f;
    font-size: 1.02rem;
    line-height: 1.45;
    font-weight: 600;
    border: 0;
    border-radius: 0;
    box-sizing: border-box;
    transition: background-color 0.2s ease, color 0.2s ease;
    max-width: 100%;
}

.woocommerce-checkout ul#shipping_method > li > label:hover {
    background: #fcfcfc;
    color: #1f1f1f;
}

/* Радио-кнопка доставки */
.woocommerce-checkout ul#shipping_method > li > label::before {
    content: none !important;
    display: none !important;
}

.woocommerce-checkout ul#shipping_method > li > label::after {
    content: "";
    position: absolute;
    left: 24px;
    top: 50%;
    width: 18px;
    height: 18px;
    margin-top: -9px;
    border: 2px solid #555 !important;
    border-radius: 50%;
    background: #fff !important;
    box-shadow: none !important;
    transform: none !important;
    transition: border-color 0.2s ease, background 0.2s ease;
    z-index: 2;
}

/* Выбранная доставка */
.woocommerce-checkout ul#shipping_method > li > input[type="radio"]:checked + label {
    background: #eef8f0;
    color: #1f1f1f;
}

.woocommerce-checkout ul#shipping_method > li > input[type="radio"]:checked + label::after {
    border-color: #3f5f43 !important;
    background: radial-gradient(circle, #3f5f43 0 3px, #fff 4px 100%) !important;
}

.woocommerce-checkout ul#shipping_method > li:hover > label::after {
    border-color: #444 !important;
}

/* Актуальная цена */
.woocommerce-checkout ul#shipping_method > li > label .amount,
.woocommerce-checkout ul#shipping_method > li > label .woocommerce-Price-amount,
.woocommerce-checkout ul#shipping_method > li > label .price,
.woocommerce-checkout ul#shipping_method > li > label .price .amount,
.woocommerce-checkout ul#shipping_method > li > label .price .woocommerce-Price-amount {
    color: #ff2a1a !important;
    font-weight: 700;
    display: inline !important;
    white-space: nowrap;
}

/* Старая цена */
.woocommerce-checkout ul#shipping_method > li > font,
.woocommerce-checkout ul#shipping_method > li > del,
.woocommerce-checkout ul#shipping_method > li > strike,
.woocommerce-checkout ul#shipping_method > li > s {
    position: absolute;
    top: 22px;
    right: 22px;
    display: inline-block !important;
    margin: 0;
    padding: 0;
    line-height: 1.45;
    font-size: 1.02rem;
    font-weight: 500;
    white-space: nowrap;
    background: transparent !important;
    z-index: 3;
    pointer-events: none;
}

.woocommerce-checkout ul#shipping_method > li > input[type="radio"]:checked ~ font,
.woocommerce-checkout ul#shipping_method > li > input[type="radio"]:checked ~ del,
.woocommerce-checkout ul#shipping_method > li > input[type="radio"]:checked ~ strike,
.woocommerce-checkout ul#shipping_method > li > input[type="radio"]:checked ~ s {
    background: transparent !important;
}

.woocommerce-checkout ul#shipping_method > li > font,
.woocommerce-checkout ul#shipping_method > li > font *,
.woocommerce-checkout ul#shipping_method > li > del,
.woocommerce-checkout ul#shipping_method > li > del *,
.woocommerce-checkout ul#shipping_method > li > strike,
.woocommerce-checkout ul#shipping_method > li > strike *,
.woocommerce-checkout ul#shipping_method > li > s,
.woocommerce-checkout ul#shipping_method > li > s * {
    color: #5f5f6b !important;
    opacity: 1 !important;
    font-weight: 500 !important;
    text-decoration-thickness: 1px;
}

/* Всё, что ниже верхней строки */
.woocommerce-checkout ul#shipping_method > li > *:not(label):not(font):not(del):not(strike):not(s):not(input) {
    flex: 0 0 100%;
    width: 100%;
    box-sizing: border-box;
    padding-left: 22px;
    padding-right: 22px;
    max-width: 100%;
}

.woocommerce-checkout ul#shipping_method > li > label + *:not(font):not(del):not(strike):not(s):not(input),
.woocommerce-checkout ul#shipping_method > li > font + *,
.woocommerce-checkout ul#shipping_method > li > del + *,
.woocommerce-checkout ul#shipping_method > li > strike + *,
.woocommerce-checkout ul#shipping_method > li > s + * {
    border-top: 1px solid #ececec;
    padding-top: 14px;
    margin-top: 0;
}

/* Текст после верхней строки */
.woocommerce-checkout ul#shipping_method small,
.woocommerce-checkout ul#shipping_method .shipping-method-description,
.woocommerce-checkout ul#shipping_method br + * {
    color: #666;
    font-weight: 400;
}

/* Кнопки выбора ПВЗ и т.п. */
.woocommerce-checkout ul#shipping_method button:not([hidden]),
.woocommerce-checkout ul#shipping_method .button:not([hidden]),
.woocommerce-checkout ul#shipping_method a.button:not([hidden]) {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    width: auto !important;
    min-width: 0 !important;
    max-width: 100%;
    min-height: 46px;
    padding: 0 22px;
    border-radius: 10px !important;
    font-weight: 600;
    white-space: nowrap;
    background: #f3f3f3;
    border: 1px solid #cfcfcf;
    color: #2f2f2f;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
    cursor: pointer;
    transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, transform 0.05s ease;
    text-decoration: none;
    margin-left: auto !important;
    margin-right: auto !important;
}

.woocommerce-checkout ul#shipping_method button:not([hidden]):hover,
.woocommerce-checkout ul#shipping_method .button:not([hidden]):hover,
.woocommerce-checkout ul#shipping_method a.button:not([hidden]):hover {
    background: #ebebeb;
    border-color: #bdbdbd;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

.woocommerce-checkout ul#shipping_method button:not([hidden]):active,
.woocommerce-checkout ul#shipping_method .button:not([hidden]):active,
.woocommerce-checkout ul#shipping_method a.button:not([hidden]):active {
    transform: translateY(1px);
}

/* Вертикальная структура: действие -> результат */
.woocommerce-checkout ul#shipping_method > li p,
.woocommerce-checkout ul#shipping_method > li .pickup-location,
.woocommerce-checkout ul#shipping_method > li .pickup-point,
.woocommerce-checkout ul#shipping_method > li .pickup-point-button-wrap,
.woocommerce-checkout ul#shipping_method > li .eshoplogistic-widget,
.woocommerce-checkout ul#shipping_method > li .widget_ships {
    text-align: left;
}

/* Дублированный блок пункта выдачи */
.pickup-duplicate,
.delivery-address-duplicate {
    display: none;
    width: 100%;
    max-width: 420px;
    margin: 12px auto 18px auto;
    padding: 14px 16px;
    border-radius: 12px;
    background: #f6f7f8;
    border: 1px solid #ececec;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    font-size: 0.95rem;
    line-height: 1.5;
    color: #444;
    opacity: 0;
    transform: translateY(6px);
    transition: opacity 0.22s ease, transform 0.22s ease;
    pointer-events: none;
}

.pickup-duplicate.is-visible,
.delivery-address-duplicate.is-visible {
    display: block;
    opacity: 1;
    transform: translateY(0);
}

.pickup-duplicate strong,
.delivery-address-duplicate strong {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
}

.pickup-duplicate .pickup-value,
.delivery-address-duplicate .delivery-address-value {
    color: #1f1f1f;
    font-weight: 500;
}

/* Футер итога */
.woocommerce-checkout .shop_table.woocommerce-checkout-review-order-table tr.order-total {
    border-top: 1px solid #e8e8e8;
}

.woocommerce-checkout .shop_table.woocommerce-checkout-review-order-table tr.order-total th,
.woocommerce-checkout .shop_table.woocommerce-checkout-review-order-table tr.order-total td {
    background: transparent;
    border: 0;
    padding: 18px 0 0 0;
    vertical-align: middle;
}

.woocommerce-checkout .shop_table.woocommerce-checkout-review-order-table tr.order-total th {
    font-size: 1.45rem;
    line-height: 1.2;
    font-weight: 400;
    color: #4a4a4a;
    text-align: left;
    white-space: nowrap;
}

.woocommerce-checkout .shop_table.woocommerce-checkout-review-order-table tr.order-total td {
    font-size: 1.75rem;
    line-height: 1.1;
    font-weight: 700;
    color: #2f2f2f;
    text-align: right;
    white-space: nowrap;
}

/* =========================
   БЛОК ОПЛАТЫ
   ========================= */

.woocommerce-checkout #payment {
    margin-top: 22px;
}

.woocommerce-checkout #payment ul.payment_methods {
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
}

.woocommerce #payment .wc_payment_methods li,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod {
    position: relative;
    margin: 0 0 14px 0;
    padding: 0;
    list-style: none;
    border: 1px solid #dddddd;
    border-radius: 14px;
    background: #ffffff;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.woocommerce #payment li:hover,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method:hover,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod:hover {
    border-color: #cfcfcf;
    box-shadow: 0 4px 16px rgba(0,0,0,0.05);
}

.woocommerce #payment input[type="radio"],
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method > input[type="radio"],
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > input[type="radio"] {
    position: absolute !important;
    left: 0 !important;
    top: 0 !important;
    width: 1px !important;
    height: 1px !important;
    margin: 0 !important;
    padding: 0 !important;
    opacity: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
    clip: rect(0 0 0 0) !important;
    clip-path: inset(50%) !important;
    white-space: nowrap !important;
    appearance: none !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
}

.woocommerce #payment li > label,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label {
    display: block;
    width: 100%;
    margin: 0;
    padding: 22px 72px 22px 62px;
    position: relative;
    cursor: pointer;
    background: #ffffff;
    color: #2f2f2f;
    font-size: 1.08rem;
    line-height: 1.35;
    font-weight: 600;
    border: 0;
    border-radius: 0;
    box-sizing: border-box;
    transition: background-color 0.2s ease, color 0.2s ease;
}

.woocommerce #payment li > label:hover,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label:hover,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label:hover {
    background: #fcfcfc;
    color: #1f1f1f;
}

.woocommerce #payment li > label::before,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label::before,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label::before {
    content: none !important;
    display: none !important;
    background: none !important;
    border: 0 !important;
    box-shadow: none !important;
}

.woocommerce #payment li > label::after,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label::after,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label::after {
    content: "";
    position: absolute;
    left: 24px;
    top: 50%;
    width: 18px;
    height: 18px;
    margin-top: -9px;
    border: 2px solid #555 !important;
    border-radius: 50%;
    background: #fff !important;
    box-shadow: none !important;
    transform: none !important;
    transition: border-color 0.2s ease, background 0.2s ease;
    z-index: 2;
}

.woocommerce #payment li > input[type="radio"]:checked + label,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method > input[type="radio"]:checked + label,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > input[type="radio"]:checked + label {
    background: linear-gradient(180deg, #fcfcfc 0%, #f7f7f7 100%);
    color: #1f1f1f;
}

.woocommerce #payment li > input[type="radio"]:checked + label::after,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method > input[type="radio"]:checked + label::after,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > input[type="radio"]:checked + label::after {
    border-color: #444 !important;
    background: radial-gradient(circle, #444 0 3px, #fff 4px 100%) !important;
    box-shadow: none !important;
}

.woocommerce #payment li:hover > label::after,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method:hover > label::after,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod:hover > label::after {
    border-color: #444 !important;
}

.woocommerce #payment li > label img,
.woocommerce #payment li > label svg,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label img,
.woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label svg,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label img,
.woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label svg {
    max-height: 28px;
    width: auto;
    float: right;
    margin-left: 12px;
}

.woocommerce #payment div.payment_box {
    margin: 0;
    padding: 18px 22px 20px 62px;
    background: #fbfbfb;
    border-top: 1px solid #ececec;
    color: #666666;
    font-weight: 400;
    line-height: 1.7;
    font-size: 0.97rem;
    box-shadow: none;
}

.woocommerce #payment div.payment_box::before,
.woocommerce #payment div.payment_box::after {
    display: none !important;
    content: none !important;
}

.woocommerce #payment li label .payment_box {
    display: block;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #ececec;
    font-weight: 400;
    line-height: 1.7;
    color: #666666;
}

/* Кнопка place order */
#order_review #place_order {
    padding: 1rem 1rem;
    font-size: 1.2rem;
    line-height: 1.3333;
    font-weight: 700;
    border-radius: 10px;
    text-align: center;
    display: inline-block;
    width: 100%;
    background: linear-gradient(180deg, #5f5f5f 0%, #3f3f3f 100%);
    border: 1px solid #3a3a3a;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    cursor: pointer;
    transition: all 0.2s ease;
}

#order_review #place_order:hover {
    background: linear-gradient(180deg, #4a4a4a 0%, #2f2f2f 100%);
    border-color: #2a2a2a;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

#order_review #place_order:active {
    transform: translateY(1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.15);
}

#order_review #place_order:disabled,
#order_review #place_order.disabled,
#order_review #place_order[disabled] {
    background: #cfcfcf;
    border-color: #cfcfcf;
    color: #777;
    box-shadow: none;
    cursor: not-allowed;
}

/* Вспомогательный класс */
.semi-transparent-button {
    display: block;
    box-sizing: border-box;
    margin: 0 auto;
    padding: 8px;
    width: 80%;
    max-width: 200px;
    background: #fff;
    background: rgba(255, 255, 255, 0.7);
    border-radius: 8px;
    color: #000;
    text-align: center;
    text-decoration: none;
    font-size: larger;
    letter-spacing: 1px;
    transition: all 0.3s ease-out;
}

/* =========================
   ВАШ КАСТОМНЫЙ БЛОК ИНВОЙСА
   ========================= */

#walls-invoice-payment-fields input[type="text"],
#walls-invoice-payment-fields textarea {
    border-radius: 10px !important;
    box-sizing: border-box;
}

#walls-invoice-payment-fields #invoice_company_inn {
    width: 100% !important;
    max-width: 320px;
    min-height: 48px;
    background: #fff !important;
    border-radius: 10px !important;
    box-sizing: border-box;
}

#walls-invoice-payment-fields #invoice_company_name {
    border-radius: 10px !important;
}

#walls-invoice-payment-fields #invoice_company_address {
    min-height: auto;
    border-radius: 10px !important;
}

#walls-invoice-payment-fields #invoice_company_address.walls-invoice-payment-readonly,
#walls-invoice-payment-fields #invoice_company_address[readonly] {
    background: #f3f3f3 !important;
    color: #555 !important;
    border-color: #d7d7d7 !important;
    cursor: default;
}

#walls-invoice-payment-fields .walls-invoice-payment-inn-line {
    display: flex !important;
    align-items: stretch !important;
    gap: 12px;
    flex-wrap: nowrap;
    margin-bottom: 10px;
}

#walls-invoice-payment-fields .walls-invoice-payment-inn-row {
    flex: 0 1 320px;
    max-width: 320px;
    width: 100%;
    margin: 0 !important;
}

#walls-invoice-payment-fields .walls-invoice-payment-check-row {
    flex: 0 0 auto;
    display: flex;
    align-items: stretch;
    margin: 0 !important;
}

#walls-invoice-payment-fields #invoice_company_check_button {
    height: auto !important;
    min-height: 48px;
    min-width: 140px;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    margin: 0 !important;
    padding: 0 20px;
    border-radius: 10px !important;
    font-weight: 600;
    white-space: nowrap;
    background: #f3f3f3;
    border: 1px solid #cfcfcf;
    color: #2f2f2f;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
    cursor: pointer;
    transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, transform 0.05s ease;
}

#walls-invoice-payment-fields #invoice_company_check_button:hover {
    background: #ebebeb;
    border-color: #bdbdbd;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

#walls-invoice-payment-fields #invoice_company_check_button:active {
    transform: translateY(1px);
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
}

#walls-invoice-payment-fields .walls-invoice-payment-message,
#walls-invoice-payment-fields .walls-invoice-payment-error {
    display: block;
    margin: 12px 0 14px;
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 15px;
    line-height: 1.45;
}

#walls-invoice-payment-fields .walls-invoice-payment-message {
    background: #eef6ff;
    border: 1px solid #cfe0f5;
    color: #375b84;
}

#walls-invoice-payment-fields .walls-invoice-payment-error {
    background: #fff1f1;
    border: 1px solid #efc7c7;
    color: #9a3a3a;
}

/* ЭДО */
#walls-invoice-payment-fields #invoice_company_edo_wrap {
    display: none;
    margin-top: 8px;
    margin-bottom: 12px;
}

#walls-invoice-payment-fields .walls-invoice-payment-edo-line {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: nowrap;
}

#walls-invoice-payment-fields #invoice_company_edo_row {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    white-space: nowrap;
    width: auto;
}

#walls-invoice-payment-fields #invoice_company_edo_row input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin: 0;
    flex: 0 0 auto;
}

#walls-invoice-payment-fields #invoice_company_edo_operator_row {
    display: none;
    margin: 0;
    flex: 0 1 320px;
    min-width: 220px;
    max-width: 320px;
}

#walls-invoice-payment-fields #invoice_company_edo_operator {
    width: 100%;
    height: 44px;
    min-height: 44px;
    max-width: 320px;
    border-radius: 10px !important;
    box-sizing: border-box;
    resize: none;
    background: #ffffff !important;
    color: #2f2f2f !important;
    border: 1px solid #cfcfcf !important;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.04);
}

#walls-invoice-payment-fields #invoice_company_edo_operator:hover {
    border-color: #bdbdbd !important;
}

#walls-invoice-payment-fields #invoice_company_edo_operator:focus {
    border-color: #7a9c7f !important;
    box-shadow: 0 0 0 2px rgba(63, 95, 67, 0.15);
    outline: none;
}

#walls-invoice-payment-fields #invoice_company_edo_operator::placeholder {
    color: #9a9a9a;
}

#walls-invoice-payment-fields #invoice_company_edo_operator:not(:placeholder-shown) {
    border-color: #7a9c7f !important;
    box-shadow: 0 0 0 2px rgba(63, 95, 67, 0.12);
}

#walls-invoice-payment-fields #invoice_company_edo_operator:not(:placeholder-shown):focus {
    border-color: #5f8a66 !important;
    box-shadow: 0 0 0 2px rgba(63, 95, 67, 0.18);
}

/* =========================
   МОБИЛЬНАЯ ВЕРСИЯ
   ========================= */

@media (min-width: 768px) {
    .woocommerce-checkout #customer_details .form-row-first,
    .woocommerce-checkout #customer_details .form-row-last {
        width: calc(50% - 18px) !important;
    }

    .woocommerce-checkout #customer_details .form-row-first {
        float: left !important;
        clear: left !important;
    }

    .woocommerce-checkout #customer_details .form-row-last {
        float: right !important;
        clear: right !important;
    }

    .woocommerce-checkout #customer_details .form-row-wide {
        float: none !important;
        clear: both !important;
        width: 100% !important;
    }
}

@media (max-width: 767px) {
    .woocommerce-checkout #customer_details .form-row-first,
    .woocommerce-checkout #customer_details .form-row-last,
    .woocommerce-checkout #customer_details .form-row-wide {
        float: none !important;
        width: 100% !important;
        clear: both !important;
    }

    .woocommerce-checkout #customer_details .woocommerce-billing-fields,
    .woocommerce-checkout #customer_details .woocommerce-additional-fields {
        padding: 20px 18px;
        border-radius: 14px;
    }

    .woocommerce-checkout #customer_details .woocommerce-billing-fields > h3,
    .woocommerce-checkout #customer_details .woocommerce-additional-fields > h3,
    .woocommerce-checkout #customer_details h3,
    .woocommerce-checkout #order_review_heading {
        font-size: 1.6rem;
        margin-bottom: 16px;
    }

    .woocommerce-checkout-review-order-table thead th,
    .woocommerce-checkout-review-order-table tbody tr > th,
    .woocommerce-checkout-review-order-table tbody tr > td,
    .woocommerce-checkout-review-order-table tr.cart-subtotal > th,
    .woocommerce-checkout-review-order-table tr.cart-subtotal > td {
        padding: 14px 14px;
    }

    .woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping.just_label h3,
    .woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping:not(.just_label) > th {
        font-size: 1.35rem;
    }

    .woocommerce-checkout ul#shipping_method > li > label {
        padding: 18px 110px 18px 52px;
        font-size: 0.98rem;
    }

    .woocommerce-checkout ul#shipping_method > li > label::after {
        left: 24px;
    }

    .woocommerce-checkout ul#shipping_method > li > font,
    .woocommerce-checkout ul#shipping_method > li > del,
    .woocommerce-checkout ul#shipping_method > li > strike,
    .woocommerce-checkout ul#shipping_method > li > s {
        top: 18px;
        right: 16px;
        font-size: 0.96rem;
    }

    .woocommerce-checkout .shop_table.woocommerce-checkout-review-order-table tr.order-total th,
    .woocommerce-checkout-review-order-table tr.order-total > th {
        font-size: 1.2rem;
    }

    .woocommerce-checkout .shop_table.woocommerce-checkout-review-order-table tr.order-total td,
    .woocommerce-checkout-review-order-table tr.order-total > td {
        font-size: 1.45rem;
    }

    .woocommerce #payment li > label,
    .woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label,
    .woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label {
        padding: 18px 56px 18px 52px;
        font-size: 1rem;
    }

    .woocommerce #payment li > label::after,
    .woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label::after,
    .woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label::after {
        left: 24px;
    }

    .woocommerce #payment div.payment_box {
        padding: 15px 16px 18px 52px;
        font-size: 0.95rem;
    }

    .woocommerce #payment li > label img,
    .woocommerce #payment li > label svg,
    .woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label img,
    .woocommerce-checkout #payment ul.payment_methods .wc_payment_method > label svg,
    .woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label img,
    .woocommerce-checkout #payment ul.payment_methods .woocommerce-PaymentMethod > label svg {
        max-height: 24px;
    }

    #walls-invoice-payment-fields .walls-invoice-payment-inn-line {
        gap: 8px;
        align-items: stretch !important;
        flex-wrap: nowrap;
    }

    #walls-invoice-payment-fields .walls-invoice-payment-inn-row {
        flex: 1 1 auto;
        max-width: none;
        min-width: 0;
    }

    #walls-invoice-payment-fields #invoice_company_inn {
        max-width: none;
    }

    #walls-invoice-payment-fields .walls-invoice-payment-check-row {
        flex: 0 0 auto;
    }

    #walls-invoice-payment-fields #invoice_company_check_button {
        min-width: 112px;
        min-height: 48px;
        padding: 0 16px;
        font-size: 15px;
    }

    #walls-invoice-payment-fields #invoice_company_edo_wrap {
        margin-top: 10px;
        margin-bottom: 12px;
    }

    #walls-invoice-payment-fields .walls-invoice-payment-edo-line {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    #walls-invoice-payment-fields #invoice_company_edo_row {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }

    #walls-invoice-payment-fields #invoice_company_edo_operator_row {
        width: 100%;
        max-width: none;
        min-width: 0;
    }

    #walls-invoice-payment-fields #invoice_company_edo_operator {
        width: 100%;
        max-width: none;
    }

    .pickup-duplicate,
    .delivery-address-duplicate {
        max-width: none;
        margin: 10px 0 18px 0;
    }
}
CSS;

    wp_register_style('walls-invoice-payment', false, array(), WALLS_INVOICE_PAYMENT_VERSION);
    wp_enqueue_style('walls-invoice-payment');
    wp_add_inline_style('walls-invoice-payment', $inline_css);

$pickup_js = <<<'JS'
(function($){
    var lastPickupValue = '';
    var pollingTimer = null;
    var observer = null;

    function getPickupField() {
        return $('#wc_esl_billing_terminal, #wc_esl_shipping_terminal').filter('input, select, textarea');
    }

    function getPickupValue() {
        var value = '';
    
        getPickupField().each(function(){
            var current = $.trim($(this).val() || '');
    
            if (current) {
                value = current;
                return false;
            }
        });
    
        return value;
    }

    function isEslPickupMethod(methodId) {
        return typeof methodId === 'string'
            && methodId.indexOf('wc_esl_') !== -1
            && methodId.indexOf('_terminal') !== -1;
    }

    function isEslCourierMethod(methodId) {
        return typeof methodId === 'string'
            && methodId.indexOf('wc_esl_') !== -1
            && methodId.indexOf('_door') !== -1;
    }

    function getSelectedShippingMethod() {
        var $checked = $('input[name^="shipping_method"]:checked');
        if ($checked.length) {
            return $checked.val();
        }

        var $select = $('select[name^="shipping_method"]');
        if ($select.length) {
            return $select.val();
        }

        var $hidden = $('input[name^="shipping_method"][type="hidden"]');
        if ($hidden.length) {
            return $hidden.val();
        }

        var $any = $('input[name^="shipping_method"]');
        if ($any.length) {
            return $any.first().val();
        }

        return '';
    }

    function getBillingAddressValue() {
        return $.trim($('#billing_address_1').val() || '');
    }

    function getShippingMethodContainer($methodField) {
        var $container = $methodField.closest('li');

        if ($container.length) {
            return $container;
        }

        $container = $methodField.closest('#shipping_method');
        if ($container.length) {
            return $container;
        }

        return $methodField.parent();
    }

    function getSelectedPickupContainer(selectedMethod) {
        var $container = $();

        $('input[name^="shipping_method"], select[name^="shipping_method"]').each(function(){
            var $methodField = $(this);

            if (($methodField.val() || '') === selectedMethod) {
                $container = getShippingMethodContainer($methodField);
                return false;
            }
        });

        return $container;
    }

    function getVisiblePickupMethod() {
        var $visible = $('#shipping_method .pickup-duplicate.is-visible');

        if (!$visible.length) {
            return '';
        }

        var $methodField = $visible
            .closest('li')
            .find('input[name^="shipping_method"], select[name^="shipping_method"]')
            .first();

        if (!$methodField.length) {
            $methodField = $visible
                .siblings('input[name^="shipping_method"], select[name^="shipping_method"]')
                .first();
        }

        return $methodField.val() || '';
    }

    function ensureCourierAddressBlocks() {
        $('input[name^="shipping_method"], select[name^="shipping_method"]').each(function(){
            var $methodField = $(this);
            var methodId = $methodField.val() || '';

            if (!isEslCourierMethod(methodId)) {
                return;
            }

            var $container = getShippingMethodContainer($methodField);
            if (!$container.length || $container.find('.delivery-address-duplicate').length) {
                return;
            }

            $container.append(
                '<div class="delivery-address-duplicate">' +
                    '<strong>Адрес доставки:</strong>' +
                    '<div class="delivery-address-value"></div>' +
                '</div>'
            );
        });
    }

    function updateCourierAddressInCards() {
        ensureCourierAddressBlocks();

        var selectedMethod = getSelectedShippingMethod();
        var address = getBillingAddressValue();
        var addressText = address || 'укажите выше, сразу после своего города';

        $('.delivery-address-duplicate').each(function(){
            var $duplicate = $(this);
            var $container = $duplicate.closest('li');
            var $methodField = $container.find('input[name^="shipping_method"], select[name^="shipping_method"]').first();

            if (!$methodField.length) {
                $methodField = $duplicate.siblings('input[name^="shipping_method"], select[name^="shipping_method"]').first();
            }

            var methodId = $methodField.val() || selectedMethod;
            var isChosen = isEslCourierMethod(selectedMethod) && methodId === selectedMethod;

            if (isChosen) {
                $duplicate.find('.delivery-address-value').text(addressText);
                $duplicate.addClass('is-visible');
            } else {
                $duplicate.find('.delivery-address-value').text('');
                $duplicate.removeClass('is-visible');
            }
        });
    }

    function ensurePickupBlocks() {
        $('input[name^="shipping_method"], select[name^="shipping_method"]').each(function(){
            var $methodField = $(this);
            var methodId = $methodField.val() || '';

            if (!isEslPickupMethod(methodId)) {
                return;
            }

            var $container = getShippingMethodContainer($methodField);
            if (!$container.length || $container.find('.pickup-duplicate').length) {
                return;
            }

            $container.append(
                '<div class="pickup-duplicate">' +
                    '<strong>Пункт выдачи:</strong>' +
                    '<div class="pickup-value"></div>' +
                '</div>'
            );
        });
    }

    function updatePickupInCards() {
        ensurePickupBlocks();

        var value = getPickupValue();
        var selectedMethod = getSelectedShippingMethod();
        var isPickupSelected = isEslPickupMethod(selectedMethod);
        var $selectedContainer = isPickupSelected ? getSelectedPickupContainer(selectedMethod) : $();

        lastPickupValue = value;

        $('.pickup-duplicate').each(function(){
            var $duplicate = $(this);
            var isChosen = isPickupSelected
                && value
                && $selectedContainer.length
                && $selectedContainer.find($duplicate).length > 0;

            if (isChosen) {
                $duplicate.find('.pickup-value').text(value);
                $duplicate.addClass('is-visible');
            } else {
                $duplicate.find('.pickup-value').text('');
                $duplicate.removeClass('is-visible');
            }
        });
    }

    function delayedRefreshSeries() {
        updatePickupInCards();
        updateCourierAddressInCards();
        setTimeout(updatePickupInCards, 100);
        setTimeout(updateCourierAddressInCards, 100);
        setTimeout(updatePickupInCards, 250);
        setTimeout(updateCourierAddressInCards, 250);
        setTimeout(updatePickupInCards, 500);
        setTimeout(updateCourierAddressInCards, 500);
        setTimeout(updatePickupInCards, 900);
        setTimeout(updateCourierAddressInCards, 900);
        setTimeout(updatePickupInCards, 1500);
        setTimeout(updateCourierAddressInCards, 1500);
    }

    function stopPolling() {
        if (pollingTimer) {
            clearInterval(pollingTimer);
            pollingTimer = null;
        }
    }

    function startPolling() {
        stopPolling();
        pollingTimer = setInterval(function(){
            var current = getPickupValue();
            var chosenNow = getSelectedShippingMethod();
            var visibleChosen = getVisiblePickupMethod();

            if (current !== lastPickupValue || chosenNow !== visibleChosen) {
                updatePickupInCards();
            }

            updateCourierAddressInCards();
        }, 500);
    }

    function attachObserver() {
        var fields = getPickupField().toArray();
    
        if (observer) {
            observer.disconnect();
            observer = null;
        }
    
        if (!fields.length) {
            return;
        }
    
        observer = new MutationObserver(function(){
            updatePickupInCards();
            updateCourierAddressInCards();
        });
    
        fields.forEach(function(field){
            observer.observe(field, {
                attributes: true,
                attributeFilter: ['value']
            });
        });
    }

    $(document).ready(function(){
        delayedRefreshSeries();
        attachObserver();
        startPolling();
    });

    $(document.body).on('updated_checkout', function(){
        delayedRefreshSeries();
        setTimeout(function(){
            attachObserver();
            startPolling();
        }, 250);
    });

    $(document).on('change', 'input[name^="shipping_method"], select[name^="shipping_method"]', function(){
        delayedRefreshSeries();
    });

    $(document).on('change input', '#wc_esl_billing_terminal, #wc_esl_shipping_terminal', function(){
        updatePickupInCards();
    });

    $(document).on('click', '#shipping_method button, #shipping_method .button, #shipping_method a.button', function(){
        delayedRefreshSeries();
    });

    $(document).on('change input', '#billing_address_1', function(){
        updateCourierAddressInCards();
    });

})(jQuery);
JS;

wp_add_inline_script('walls-invoice-payment', $pickup_js);

}
add_action('wp_enqueue_scripts', 'walls_invoice_payment_enqueue_assets');

function walls_invoice_payment_ajax_lookup() {
    check_ajax_referer(WALLS_INVOICE_PAYMENT_NONCE_ACTION, 'nonce');

    if (!class_exists('WooCommerce')) {
        wp_send_json_error(array('message' => 'WooCommerce не активен.'), 400);
    }

    $gateway_settings = get_option('woocommerce_' . WALLS_INVOICE_PAYMENT_GATEWAY_ID . '_settings', array());
    $token = isset($gateway_settings['dadata_token']) ? trim((string) $gateway_settings['dadata_token']) : '';

    if ($token === '') {
        wp_send_json_error(array('message' => 'Не настроен Dadata API Token.'), 500);
    }

    $inn = isset($_POST['inn']) ? preg_replace('/\D+/', '', wp_unslash($_POST['inn'])) : '';
    if (!in_array(strlen($inn), array(10, 12), true)) {
        wp_send_json_error(array('message' => 'ИНН должен содержать 10 цифр для юрлица или 12 цифр для ИП.'), 400);
    }

    $company_type = strlen($inn) === 10 ? 'LEGAL' : 'INDIVIDUAL';

    $body = array(
        'query'  => $inn,
        'type'   => $company_type,
        'status' => array('ACTIVE'),
        'count'  => 1,
    );

    if ($company_type === 'LEGAL') {
        $body['branch_type'] = 'MAIN';
    }

    $response = wp_remote_post('https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party', array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Token ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ),
        'body' => wp_json_encode($body),
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Не удается найти данные по ИНН.'), 500);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);

    if ($code === 403 || $code === 429) {
        wp_send_json_error(array('message' => 'Не удается найти данные по ИНН.'), 503);
    }

    if ($code < 200 || $code >= 300 || !is_array($json)) {
        wp_send_json_error(array('message' => 'Не удается найти данные по ИНН.'), 500);
    }

    if (empty($json['suggestions']) || !is_array($json['suggestions'])) {
        wp_send_json_error(array('message' => 'Компания не найдена, проверьте введенный ИНН.'), 404);
    }

    $item  = $json['suggestions'][0];
    $data  = isset($item['data']) && is_array($item['data']) ? $item['data'] : array();
    $state = isset($data['state']) && is_array($data['state']) ? $data['state'] : array();

    if ((isset($state['status']) ? $state['status'] : '') !== 'ACTIVE') {
        wp_send_json_error(array('message' => 'Компания не найдена, проверьте введенный ИНН.'), 404);
    }

    if ((isset($data['type']) ? $data['type'] : '') !== $company_type) {
        wp_send_json_error(array('message' => 'Компания не найдена, проверьте введенный ИНН.'), 404);
    }

    if ($company_type === 'LEGAL' && (isset($data['branch_type']) ? $data['branch_type'] : '') !== 'MAIN') {
        wp_send_json_error(array('message' => 'Компания не найдена, проверьте введенный ИНН.'), 404);
    }

    $name_data     = isset($data['name']) && is_array($data['name']) ? $data['name'] : array();
    $address_data  = isset($data['address']) && is_array($data['address']) ? $data['address'] : array();
    $address_inner = isset($address_data['data']) && is_array($address_data['data']) ? $address_data['data'] : array();

    if ($company_type === 'LEGAL') {
        $company_name = !empty($name_data['short_with_opf']) ? (string) $name_data['short_with_opf'] : (isset($name_data['full_with_opf']) ? (string) $name_data['full_with_opf'] : '');
        $address      = !empty($address_data['unrestricted_value']) ? (string) $address_data['unrestricted_value'] : (isset($address_data['value']) ? (string) $address_data['value'] : '');
        $city_hint    = '';
    } else {
        $company_name = !empty($item['value']) ? (string) $item['value'] : (isset($name_data['full_with_opf']) ? (string) $name_data['full_with_opf'] : '');
        $address      = '';
        $city_hint    = !empty($address_data['unrestricted_value']) ? (string) $address_data['unrestricted_value'] : (isset($address_data['value']) ? (string) $address_data['value'] : '');

        if ($city_hint === '' && !empty($address_inner['city_with_type'])) {
            $city_hint = (string) $address_inner['city_with_type'];
        }
    }

    if ($company_name === '') {
        wp_send_json_error(array('message' => 'Компания не найдена, проверьте введенный ИНН.'), 404);
    }

    wp_send_json_success(array(
        'company_type' => $company_type,
        'company_name' => $company_name,
        'address'      => $address,
        'city_hint'    => $city_hint,
        'inn'          => $inn,
    ));
}
add_action('wp_ajax_walls_invoice_payment_dadata_lookup', 'walls_invoice_payment_ajax_lookup');
add_action('wp_ajax_nopriv_walls_invoice_payment_dadata_lookup', 'walls_invoice_payment_ajax_lookup');

function walls_invoice_payment_admin_order_data($order) {
    if (!$order instanceof WC_Order) {
        return;
    }

    if ($order->get_payment_method() !== WALLS_INVOICE_PAYMENT_GATEWAY_ID) {
        return;
    }

    $inn          = $order->get_meta('_invoice_company_inn');
    $name         = $order->get_meta('_invoice_company_name');
    $address      = $order->get_meta('_invoice_company_address');
    $type         = $order->get_meta('_invoice_company_type');
    $city         = $order->get_meta('_invoice_company_city_hint');
    $edo          = $order->get_meta('_invoice_company_edo');
    $edo_operator = $order->get_meta('_invoice_company_edo_operator');

    if ($inn === '' && $name === '' && $address === '' && $city === '') {
        return;
    }

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:12px 14px;margin:16px 0;">';
    echo '<h4 style="margin:0 0 10px;padding:0;">Данные для оплаты по счету</h4>';

    if ($type !== '') {
        echo '<p><strong>Тип:</strong> ' . esc_html($type === 'INDIVIDUAL' ? 'ИП' : 'Юрлицо') . '</p>';
    }

    if ($inn !== '') {
        echo '<p><strong>ИНН:</strong> ' . esc_html($inn) . '</p>';
    }

    if ($name !== '') {
        echo '<p><strong>Наименование:</strong> ' . esc_html($name) . '</p>';
    }

    if ($address !== '') {
        echo '<p><strong>Юридический адрес:</strong> ' . esc_html($address) . '</p>';
    }

    if ($city !== '' && $type === 'INDIVIDUAL') {
        echo '<p><strong>Определился город:</strong> ' . esc_html($city) . '</p>';
    }

    if ($edo === '1') {
        if (!empty($edo_operator)) {
            echo '<p><strong>ЭДО:</strong> ' . sprintf('Есть ЭДО, оператор %s', esc_html($edo_operator)) . '</p>';
        } else {
            echo '<p><strong>ЭДО:</strong> Есть ЭДО</p>';
        }
    }

    echo '</div>';
}
add_action('woocommerce_admin_order_data_after_billing_address', 'walls_invoice_payment_admin_order_data');

function walls_invoice_payment_hide_thankyou_sections() {
    if (!function_exists('is_order_received_page') || !is_order_received_page()) {
        return;
    }

    $order_id = absint(get_query_var('order-received'));
    if (!$order_id) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        return;
    }

    if ($order->get_payment_method() !== WALLS_INVOICE_PAYMENT_GATEWAY_ID) {
        return;
    }

    echo '<style>
    body.woocommerce-order-received .woocommerce-order-details,
    body.woocommerce-order-received .woocommerce-customer-details,
    body.woocommerce-order-received .order-again,
    body.woocommerce-order-received .woocommerce-button.pay,
    body.woocommerce-order-received .woocommerce-button.cancel {
        display:none !important;
    }
    </style>';
}
add_action('wp_head', 'walls_invoice_payment_hide_thankyou_sections');
