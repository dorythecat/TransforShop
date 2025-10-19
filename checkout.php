<?php
require_once "stripe-php/init.php";
require_once "secrets.php";

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['cart'])) {
    header("Location: index.php");
    exit();
}
$db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_DATABASE);
if (!$db) die("Connection failed: " . mysqli_connect_error());
$subtotal = 0.0;
$shipping = 0.0;
$item_ids = array_keys($_SESSION['cart']);
if (!empty($item_ids)) {
    $ids_string = implode(',', array_map('intval', $item_ids));
    $cart_query = mysqli_query($db, "SELECT * FROM items WHERE id IN ($ids_string);");
    while ($row = mysqli_fetch_array($cart_query)) {
        $item_id = $row['id'];
        $quantity = $_SESSION['cart'][$item_id];
        $subtotal += $row['price'] * $quantity;
    } $subtotal = number_format($subtotal, 2, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['address'], $_POST['email'], $_POST['phone'])) {
    $name = mysqli_real_escape_string($db, $_POST['name']);
    $address = mysqli_real_escape_string($db, $_POST['address']);
    $postal_code = mysqli_real_escape_string($db, $_POST['postal_code']);
    $country = mysqli_real_escape_string($db, $_POST['country']);
    $email = mysqli_real_escape_string($db, $_POST['email']);
    $phone = mysqli_real_escape_string($db, $_POST['phone']);
    $notes = isset($_POST['notes']) ? mysqli_real_escape_string($db, $_POST['notes']) : '';

    // Fetch all cart items with preorder status
    $item_ids = array_keys($_SESSION['cart']);
    $ids_string = implode(',', array_map('intval', $item_ids));
    $cart_items = [];
    $cart_query = mysqli_query($db, "SELECT * FROM items WHERE id IN ($ids_string);");
    while ($row = mysqli_fetch_array($cart_query)) {
        $item_id = $row['id'];
        $cart_items[$item_id] = $row;
        $cart_items[$item_id]['quantity'] = $_SESSION['cart'][$item_id];
    }

    // Split into preorder and normal
    $preorder_cart = [];
    $normal_cart = [];
    $has_preorder = false;
    foreach ($cart_items as $item) {
        if ($item['preorders_left'] <= 0) continue;
        $has_preorder = true;
        break;
    }
    foreach ($cart_items as $item_id => $item) {
        if (($has_preorder && !$_POST['preorder_separate']) || $item['preorders_left'] > 0) $preorder_cart[$item_id] = $item;
        else $normal_cart[$item_id] = $item;
    }

    // Helper to calculate shipping
    function calc_shipping($country) {
        $zone1 = ["Portugal", "United Kingdom", "Germany", "France", "Andorra", "Italy", "Belgium", "Netherlands",
                  "Luxembourg", "Ireland", "Austria", "Isle of Mann", "Denmark", "Poland", "Czech Republic", "Slovakia",
                  "Slovenia", "Hungary", "Romania", "Bulgaria", "Greece", "Croatia", "Finland", "Sweden", "Estonia",
                  "Latvia", "Lithuania"];
        $zone3 = ["United States", "Australia", "Canada", "Japan", "New Zealand", "Russia"];
        if ($country === "Spain") return 2.00;
        if (in_array($country, $zone1)) return 3.00;
        if (in_array($country, $zone3)) return 5.00;
        return 4.50; // Zone 2
    }

    // Place order for normal items, but mark it as unpaid, store the ids so we can mark them as pending when paid
    $ids = [];

    function place_order($db, &$ids, $cart, $status, $name, $address, $postal_code, $country, $email, $phone, $notes) {
        $order_items = [];
        $subtotal = 0.0;
        foreach ($cart as $item) {
            $order_items[$item['id']] = intval($item['quantity']);
            $subtotal += $item['price'] * $item['quantity'];
        }
        $shipping = calc_shipping($country);
        $total = $subtotal + $shipping;
        $order_items_json = json_encode($order_items);
        $insert_query = "INSERT INTO orders (status, name, address, postal_code, country, email, phone, items, subtotal, shipping, total, notes) 
                         VALUES ($status, '$name', '$address', '$postal_code', '$country', '$email', '$phone', '$order_items_json', '$subtotal', '$shipping', '$total', '$notes');";
        mysqli_query($db, $insert_query);

        $order_id_query = mysqli_query($db, "SELECT id FROM orders WHERE email='$email' AND items='$order_items_json' AND status=$status ORDER BY id DESC LIMIT 1;");
        $ids[] = mysqli_fetch_array($order_id_query)['id'];

        // Remove items from stock
        foreach ($cart as $item) {
            $item_id = $item['id'];
            $new_stock = max(0, $item['stock'] - $item['quantity']);
            mysqli_query($db, "UPDATE items SET stock=$new_stock WHERE id=$item_id;");
            // Also reduce preorders left if applicable
            if ($item['preorders_left'] <= 0) continue;
            $new_preorders_left = max(0, $item['preorders_left'] - $item['quantity']);
            mysqli_query($db, "UPDATE items SET preorders_left=$new_preorders_left WHERE id=$item_id;");
        }
    }

    // Place order for normal items
    if (!empty($normal_cart)) {
        place_order($db, $ids, $normal_cart, 'unpaid', $name, $address, $postal_code, $country, $email, $phone, $notes);
    }

    // Place order for preorder items
    if (!empty($preorder_cart)) {
        place_order($db, $ids, $preorder_cart, 'unpaid preorder', $name, $address, $postal_code, $country, $email, $phone, $notes);
    }

    Stripe\Stripe::setApiKey(STRIPE_API_KEY);

    // Create the Stripe checkout session
    try {
        $checkout_session = Stripe\Checkout\Session::create([
                'customer_email' => $email,
                'shipping_options' => [
                        [
                                'shipping_rate_data' => [
                                        'type' => 'fixed_amount',
                                        'fixed_amount' => [
                                                'amount' => intval(calc_shipping($country) * 100),
                                                'currency' => 'eur',
                                        ],
                                        'display_name' => 'Standard Shipping'
                                ],
                        ],
                ],
                'line_items' => array_map(function($item_id) {
                    global $db;
                    $item_query = mysqli_query($db, "SELECT * FROM items WHERE id=$item_id;");
                    $item_row = mysqli_fetch_array($item_query);
                    $quantity = $_SESSION['cart'][$item_id];
                    return [
                            'price_data' => [
                                    'currency' => 'eur',
                                    'product_data' => [
                                            'name' => $item_row['name'],
                                    ],
                                    'unit_amount' => intval($item_row['price'] * 100),
                            ],
                            'quantity' => $quantity,
                    ];
                }, array_keys($cart_items)),
                'mode' => 'payment',
                'currency' => 'eur',
                'success_url' => 'http://shop.transformate.live/index.php',
                'cancel_url' => 'http://shop.transformate.live/checkout.php',
                'automatic_tax' => ['enabled' => true ],
                'metadata' => ['order_ids' => implode(',', $ids)],
        ]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        die("Error creating Stripe checkout session: " . $e->getMessage());
    }

    $_SESSION['cart'] = [];
    header("HTTP/1.1 303 See Other");
    header("Location: " . $checkout_session->url);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>TransforMate Official Shop | Checkout</title>

    <link rel="canonical" href="https://shop.transformate.live/checkout.php">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div id="navbar">
    <h1>TransforMate Official Shop | Checkout</h1>
</div>
<table id="checkout-table">
    <tr><th>Item</th><th>Quantity</th><th>Price</th></tr>
    <?php
    $item_ids = array_keys($_SESSION['cart']);
    if (!empty($item_ids)) {
        $ids_string = implode(',', array_map('intval', $item_ids));
        $cart_query = mysqli_query($db, "SELECT * FROM items WHERE id IN ($ids_string);");
        while ($row = mysqli_fetch_array($cart_query)) {
            $item_id = $row['id'];
            $quantity = $_SESSION['cart'][$item_id];
            $total_price = number_format($row['price'] * $quantity, 2);
            echo "<tr>
                  <td>" . htmlspecialchars($row['name']) . "</td>
                  <td>" . intval($quantity) . "</td>
                  <td class='price-col'>" . $total_price . "€</td>
                  </tr>";
        }
    }
    echo "<tr>
          <td colspan='2'><strong>Subtotal</strong></td>
          <td id='subtotal' class='price-col'><strong>" . number_format($subtotal, 2) . "€</strong></td>
          </tr>";
    ?>
    <tr>
        <td colspan='2'><strong>Shipping</strong></td>
        <td id="shipping-cost" class="price-col placeholder"><span id='shipping-placeholder'>0.00€</span></td>
    </tr>
    <tr>
        <td colspan='2'><strong>Total</strong></td>
        <td id="total-cost" class="price-col placeholder"><span id='total-placeholder'>0.00€</span></td>
    </tr>
</table>
<form id="checkout-form" method="POST">
    <input type="text" name="name" placeholder="Full Name" required><br>
    <input type="text" name="address" placeholder="Shipping Address" required><br>
    <input type="number" name="postal_code" placeholder="Postal Code" required><br>
    <?php
    echo "<select id='country' name='country' required>";
    echo "<option value='' disabled selected>--- Select Country ---</option>";
    $country_list = [
            "Afghanistan", "Åland Islands", "Albania", "Algeria", "American Samoa", "Andorra", "Angola", "Anguilla",
            "Antarctica", "Antigua and Barbuda", "Argentina", "Armenia", "Aruba", "Australia", "Austria", "Azerbaijan",
            "Bahamas", "Bahrain", "Bangladesh", "Barbados", "Belarus", "Belgium", "Belize", "Benin", "Bermuda",
            "Bhutan", "Bolivia", "Bosnia and Herzegovina", "Botswana", "Bouvet Island", "Brazil",
            "British Indian Ocean Territory", "Brunei Darussalam", "Bulgaria", "Burkina Faso", "Burundi", "Cambodia",
            "Cameroon", "Canada", "Cape Verde", "Cayman Islands", "Central African Republic", "Chad", "Chile", "China",
            "Christmas Island", "Cocos (Keeling) Islands", "Colombia", "Comoros", "Congo",
            "Congo, The Democratic Republic of The", "Cook Islands", "Costa Rica", "Cote D'ivoire", "Croatia", "Cuba",
            "Cyprus", "Czech Republic", "Denmark", "Djibouti", "Dominica", "Dominican Republic", "Ecuador", "Egypt",
            "El Salvador", "Equatorial Guinea", "Eritrea", "Estonia", "Ethiopia", "Falkland Islands (Malvinas)",
            "Faroe Islands", "Fiji", "Finland", "France", "French Guiana", "French Polynesia",
            "French Southern Territories", "Gabon", "Gambia", "Georgia", "Germany", "Ghana", "Gibraltar", "Greece",
            "Greenland", "Grenada", "Guadeloupe", "Guam", "Guatemala", "Guernsey", "Guinea",
            "Guinea-bissau", "Guyana", "Haiti", "Heard Island and Mcdonald Islands", "Holy See (Vatican City State)",
            "Honduras", "Hong Kong", "Hungary", "Iceland", "India", "Indonesia", "Iran, Islamic Republic of", "Iraq",
            "Ireland", "Isle of Mann", "Israel", "Italy", "Jamaica", "Japan", "Jersey", "Jordan", "Kazakhstan", "Kenya",
            "Kiribati", "Korea, Democratic People's Republic of", "Korea, Republic of", "Kuwait", "Kyrgyzstan",
            "Lao People's Democratic Republic", "Latvia", "Lebanon", "Lesotho", "Liberia", "Libyan Arab Jamahiriya",
            "Liechtenstein", "Lithuania", "Luxembourg", "Macao", "Macedonia, The Former Yugoslav Republic of",
            "Madagascar", "Malawi", "Malaysia", "Maldives", "Mali", "Malta", "Marshall Islands", "Martinique",
            "Mauritania", "Mauritius", "Mayotte", "Mexico", "Micronesia, Federated States of", "Moldova, Republic of",
            "Monaco", "Mongolia", "Montenegro", "Montserrat", "Morocco", "Mozambique", "Myanmar", "Namibia", "Nauru",
            "Nepal", "Netherlands", "Netherlands Antilles", "New Caledonia", "New Zealand", "Nicaragua", "Niger",
            "Nigeria", "Niue", "Norfolk Island", "Northern Mariana Islands", "Norway", "Oman", "Pakistan", "Palau",
            "Palestinian Territory, Occupied", "Panama", "Papua New Guinea", "Paraguay", "Peru", "Philippines",
            "Pitcairn", "Poland", "Portugal", "Puerto Rico", "Qatar", "Reunion", "Romania", "Russian Federation",
            "Rwanda", "Saint Helena", "Saint Kitts and Nevis", "Saint Lucia", "Saint Pierre and Miquelon",
            "Saint Vincent and The Grenadines", "Samoa", "San Marino", "Sao Tome and Principe", "Saudi Arabia",
            "Senegal", "Serbia", "Seychelles", "Sierra Leone", "Singapore", "Slovakia", "Slovenia", "Solomon Islands",
            "Somalia", "South Africa", "South Georgia and The South Sandwich Islands", "Spain", "Sri Lanka", "Sudan",
            "Suriname", "Svalbard and Jan Mayen", "Swaziland", "Sweden", "Switzerland", "Syrian Arab Republic",
            "Taiwan", "Tajikistan", "Tanzania, United Republic of", "Thailand", "Timor-leste", "Togo", "Tokelau",
            "Tonga", "Trinidad and Tobago", "Tunisia", "Turkey", "Turkmenistan", "Turks and Caicos Islands", "Tuvalu",
            "Uganda", "Ukraine", "United Arab Emirates", "United Kingdom", "United States",
            "United States Minor Outlying Islands", "Uruguay", "Uzbekistan", "Vanuatu", "Venezuela", "Viet Nam",
            "Virgin Islands, British", "Virgin Islands, U.S.", "Wallis and Futuna", "Western Sahara", "Yemen", "Zambia",
            "Zimbabwe"
    ];
    foreach ($country_list as $country_name) {
        $country_name_esc = htmlspecialchars($country_name);
        echo "<option value='$country_name_esc'>$country_name_esc</option>";
    } echo "</select>";

    echo "<input type='email' name='email' placeholder='Email Address' required><br>";
    echo "<input type='number' name='phone' placeholder='Phone Number (*)' required><br>";
    echo "<input type='text' name='notes' placeholder='Additional Notes (optional)'><br>";

    $has_normal = false;
    $has_preorder = false;
    $item_ids = array_keys($_SESSION['cart']);
    if (!empty($item_ids)) {
        $ids_string = implode(',', array_map('intval', $item_ids));
        $cart_query = mysqli_query($db, "SELECT * FROM items WHERE id IN ($ids_string);");
        while ($row = mysqli_fetch_array($cart_query)) {
            if ($row['preorders_left'] <= 0) $has_normal = true;
            if ($row['preorders_left'] > 0) $has_preorder = true;
        }
    }
    if ($has_normal && $has_preorder) {
        echo '<label for="preorder_separate" style="background-color:white;color:red;padding:20px;border-radius:10px;">WARNING: PREORDER ITEMS ARE SENT WHEN MADE AVAILABLE BY DEFAULT. TO RECEIVE EVERYTHING AT THE SAME TIME (and not pay shipping two times) PLEASE UNCHECK THIS BOX.</label><br>';
        echo '<input type="checkbox" name="preorder_separate" id="preorder_separate" checked>';
    } else echo '<input type="hidden" name="preorder_separate" value="0">';

    echo "<p>* This phone number is assumed to be registered in the shipping country.
               Add in notes if this is not the case.</p>";
    ?>
    <button type="submit">Place Order</button>
    <button type="button" onclick="window.location.href='index.php'">Continue Shopping</button>
</form>
<script>
const zone1 = ['Portugal', 'United Kingdom', 'Germany', 'France', 'Andorra', 'Italy', 'Belgium', 'Netherlands', 'Luxembourg', 'Ireland', 'Austria', 'Isle of Mann', 'Denmark', 'Poland', 'Czech Republic', 'Slovakia', 'Slovenia', 'Hungary', 'Romania', 'Bulgaria', 'Greece', 'Croatia', 'Finland', 'Sweden', 'Estonia', 'Latvia', 'Lithuania'];
// Zone 2 is everyone else except zone 1 and zone 3 and Spain
const zone3 = ['United States', 'Australia', 'Canada', 'Japan', 'New Zealand', 'Russia'];
const subtotal = parseFloat(document.getElementById('subtotal').innerText.replace('€',''));
const countrySelect = document.getElementById('country');
const shippingCostTd = document.getElementById('shipping-cost');
const totalCostTd = document.getElementById('total-cost');
const preorderSeparate = document.getElementById('preorder_separate');
function updateCosts() {
    const country = countrySelect.value;
    let shipping = 4.5; // Zone 2
    if (country === 'Spain') shipping = 2.00;
    else if (zone1.includes(country)) shipping = 3.00;
    else if (zone3.includes(country)) shipping = 5.00;
    if (preorderSeparate && preorderSeparate.checked) shipping *= 2;
    shippingCostTd.innerHTML = `<strong>${shipping.toFixed(2)}€</strong>`;
    totalCostTd.innerHTML = `<strong>${(subtotal + shipping).toFixed(2)}€</strong>`;
    shippingCostTd.classList.remove('placeholder');
    totalCostTd.classList.remove('placeholder');
}
countrySelect.addEventListener('change', () => updateCosts());
preorderSeparate?.addEventListener('change', () => updateCosts());
</script>
</body>
</html>
