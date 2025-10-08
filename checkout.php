<?php
require_once "stripe-php/init.php";
require_once "secrets.php";

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['cart'])) {
    header("Location: index.php");
    exit();
}
$db = mysqli_connect("localhost", "root", "", "transforshop");
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
        if ($item['preorders_left'] > 0) {
            $has_preorder = true;
            break;
        }
    }
    foreach ($cart_items as $item_id => $item) {
        if (($has_preorder && !$_POST['preorder_separate']) || $item['preorders_left'] > 0) $preorder_cart[$item_id] = $item;
        else $normal_cart[$item_id] = $item;
    }

    // Helper to calculate shipping
    function calc_shipping($country): float {
        $shipping = 4.50; // Zone 2
        $zone1 = ["Portugal", "United Kingdom", "Germany", "France", "Andorra", "Italy", "Belgium", "Netherlands", "Luxembourg", "Ireland", "Austria", "Isle of Mann", "Denmark", "Poland", "Czech Republic", "Slovakia", "Slovenia", "Hungary", "Romania", "Bulgaria", "Greece", "Croatia", "Finland", "Sweden", "Estonia", "Latvia", "Lithuania"];
        $zone3 = ["United States", "Australia", "Canada", "Japan", "New Zealand", "Russia"];
        if ($country === "Spain") $shipping = 2.00;
        else if (in_array($country, $zone1)) $shipping = 3.00;
        else if (in_array($country, $zone3)) $shipping = 5.00;
        return $shipping;
    }

    // Place order for normal items, but mark it as unpaid, store the ids so we can mark them as pending when paid
    $ids = [];
    if (!empty($normal_cart)) {
        $order_items = [];
        $subtotal = 0.0;
        foreach ($normal_cart as $item) {
            $order_items[$item['id']] = intval($item['quantity']);
            $subtotal += $item['price'] * $item['quantity'];
        }
        $shipping = calc_shipping($country);
        $total = $subtotal + $shipping;
        $subtotal_fmt = number_format($subtotal, 2, '.', '');
        $shipping_fmt = number_format($shipping, 2, '.', '');
        $total_fmt = number_format($total, 2, '.', '');
        $order_items_json = json_encode($order_items);
        $insert_query = "INSERT INTO orders (status, name, address, postal_code, country, email, phone, items, subtotal, shipping, total, notes) 
                         VALUES ('unpaid', '$name', '$address', '$postal_code', '$country', '$email', '$phone', '$order_items_json', '$subtotal_fmt', '$shipping_fmt', '$total_fmt', '$notes');";
        mysqli_query($db, $insert_query);

        $order_id_query = mysqli_query($db, "SELECT id FROM orders WHERE email='$email' AND items='$order_items_json' AND status='unpaid' ORDER BY id DESC LIMIT 1;");
        $ids[] = mysqli_fetch_array($order_id_query)['id'];
    }

    // Place order for preorder items
    if (!empty($preorder_cart)) {
        $order_items = [];
        $subtotal = 0.0;
        foreach ($preorder_cart as $item) {
            $order_items[$item['id']] = intval($item['quantity']);
            $subtotal += $item['price'] * $item['quantity'];
        }
        $shipping = calc_shipping($country);
        $total = $subtotal + $shipping;
        $subtotal_fmt = number_format($subtotal, 2, '.', '');
        $shipping_fmt = number_format($shipping, 2, '.', '');
        $total_fmt = number_format($total, 2, '.', '');
        $order_items_json = json_encode($order_items);
        $insert_query = "INSERT INTO orders (status, name, address, postal_code, country, email, phone, items, subtotal, shipping, total, notes) 
                         VALUES ('unpaid preorder', '$name', '$address', '$postal_code', '$country', '$email', '$phone', '$order_items_json', '$subtotal_fmt', '$shipping_fmt', '$total_fmt', '$notes');";
        mysqli_query($db, $insert_query);

        // Fetch order id
        $order_id_query = mysqli_query($db, "SELECT id FROM orders WHERE email='$email' AND items='$order_items_json' AND status='unpaid preorder' ORDER BY id DESC LIMIT 1;");
        $ids[] = mysqli_fetch_array($order_id_query)['id'];

        // Update preorders_left
        foreach ($preorder_cart as $item) {
            $item_id = $item['id'];
            $new_preorders_left = max(0, $item['preorders_left'] - $item['quantity']);
            mysqli_query($db, "UPDATE items SET preorders_left=$new_preorders_left WHERE id=$item_id;");
        }
    }

    // Remove items from stock
    if (!empty($cart_items)) {
        foreach ($cart_items as $item) {
            $item_id = $item['id'];
            $new_stock = max(0, $item['stock'] - $item['quantity']);
            mysqli_query($db, "UPDATE items SET stock=$new_stock WHERE id=$item_id;");
        }
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
                'success_url' => 'http://localhost/index.php',
                'cancel_url' => 'http://localhost/checkout.php',
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
    <title>TransforMate Official Shop | Checkout</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div id="navbar">
    <h1>TransforMate Official Shop | Checkout</h1>
</div>
<table id="checkout-table">
    <tr>
        <th>Item</th>
        <th>Quantity</th>
        <th>Price</th>
    </tr>
    <?php
    $item_ids = array_keys($_SESSION['cart']);
    $ids_string = implode(',', array_map('intval', $item_ids));
    $cart_query = mysqli_query($db, "SELECT * FROM items WHERE id IN ($ids_string);");
    while ($row = mysqli_fetch_array($cart_query)) {
        $item_id = $row['id'];
        $quantity = $_SESSION['cart'][$item_id];
        $item_total = $row['price'] * $quantity;
        echo "<tr>
              <td>{$row['name']}</td>
              <td>$quantity</td>
              <td>" . number_format($item_total, 2) . "€</td>
              </tr>";
    }
    echo "<tr>
          <td colspan='2'><strong>Subtotal</strong></td>
          <td id='subtotal' class='price-col'><strong>" . number_format($subtotal, 2) . "€</strong></td>
          </tr>";
    ?>
    <tr><td colspan='2'><strong>Shipping</strong></td><td id="shipping-cost" class="price-col placeholder"><span id='shipping-placeholder'>0.00€</span></td></tr>
    <tr><td colspan='2'><strong>Total</strong></td><td id="total-cost" class="price-col placeholder"><span id='total-placeholder'>0.00€</span></td></tr>
</table>
<form id="checkout-form" method="POST">
    <input type="text" name="name" placeholder="Full Name" required><br>
    <input type="text" name="address" placeholder="Shipping Address" required><br>
    <input type="number" name="postal_code" placeholder="Postal Code" required><br>
    <select id="country" name="country" required>
        <option value="" disabled selected>--- Select Country ---</option>
        <option value="Afghanistan">Afghanistan</option>
        <option value="Åland Islands">Åland Islands</option>
        <option value="Albania">Albania</option>
        <option value="Algeria">Algeria</option>
        <option value="American Samoa">American Samoa</option>
        <option value="Andorra">Andorra</option>
        <option value="Angola">Angola</option>
        <option value="Anguilla">Anguilla</option>
        <option value="Antarctica">Antarctica</option>
        <option value="Antigua and Barbuda">Antigua and Barbuda</option>
        <option value="Argentina">Argentina</option>
        <option value="Armenia">Armenia</option>
        <option value="Aruba">Aruba</option>
        <option value="Australia">Australia</option>
        <option value="Austria">Austria</option>
        <option value="Azerbaijan">Azerbaijan</option>
        <option value="Bahamas">Bahamas</option>
        <option value="Bahrain">Bahrain</option>
        <option value="Bangladesh">Bangladesh</option>
        <option value="Barbados">Barbados</option>
        <option value="Belarus">Belarus</option>
        <option value="Belgium">Belgium</option>
        <option value="Belize">Belize</option>
        <option value="Benin">Benin</option>
        <option value="Bermuda">Bermuda</option>
        <option value="Bhutan">Bhutan</option>
        <option value="Bolivia">Bolivia</option>
        <option value="Bosnia and Herzegovina">Bosnia and Herzegovina</option>
        <option value="Botswana">Botswana</option>
        <option value="Bouvet Island">Bouvet Island</option>
        <option value="Brazil">Brazil</option>
        <option value="British Indian Ocean Territory">British Indian Ocean Territory</option>
        <option value="Brunei Darussalam">Brunei Darussalam</option>
        <option value="Bulgaria">Bulgaria</option>
        <option value="Burkina Faso">Burkina Faso</option>
        <option value="Burundi">Burundi</option>
        <option value="Cambodia">Cambodia</option>
        <option value="Cameroon">Cameroon</option>
        <option value="Canada">Canada</option>
        <option value="Cape Verde">Cape Verde</option>
        <option value="Cayman Islands">Cayman Islands</option>
        <option value="Central African Republic">Central African Republic</option>
        <option value="Chad">Chad</option>
        <option value="Chile">Chile</option>
        <option value="China">China</option>
        <option value="Christmas Island">Christmas Island</option>
        <option value="Cocos (Keeling) Islands">Cocos (Keeling) Islands</option>
        <option value="Colombia">Colombia</option>
        <option value="Comoros">Comoros</option>
        <option value="Congo">Congo</option>
        <option value="Congo, The Democratic Republic of The">Congo, The Democratic Republic of The</option>
        <option value="Cook Islands">Cook Islands</option>
        <option value="Costa Rica">Costa Rica</option>
        <option value="Cote D'ivoire">Cote D'ivoire</option>
        <option value="Croatia">Croatia</option>
        <option value="Cuba">Cuba</option>
        <option value="Cyprus">Cyprus</option>
        <option value="Czech Republic">Czech Republic</option>
        <option value="Denmark">Denmark</option>
        <option value="Djibouti">Djibouti</option>
        <option value="Dominica">Dominica</option>
        <option value="Dominican Republic">Dominican Republic</option>
        <option value="Ecuador">Ecuador</option>
        <option value="Egypt">Egypt</option>
        <option value="El Salvador">El Salvador</option>
        <option value="Equatorial Guinea">Equatorial Guinea</option>
        <option value="Eritrea">Eritrea</option>
        <option value="Estonia">Estonia</option>
        <option value="Ethiopia">Ethiopia</option>
        <option value="Falkland Islands (Malvinas)">Falkland Islands (Malvinas)</option>
        <option value="Faroe Islands">Faroe Islands</option>
        <option value="Fiji">Fiji</option>
        <option value="Finland">Finland</option>
        <option value="France">France</option>
        <option value="French Guiana">French Guiana</option>
        <option value="French Polynesia">French Polynesia</option>
        <option value="French Southern Territories">French Southern Territories</option>
        <option value="Gabon">Gabon</option>
        <option value="Gambia">Gambia</option>
        <option value="Georgia">Georgia</option>
        <option value="Germany">Germany</option>
        <option value="Ghana">Ghana</option>
        <option value="Gibraltar">Gibraltar</option>
        <option value="Greece">Greece</option>
        <option value="Greenland">Greenland</option>
        <option value="Grenada">Grenada</option>
        <option value="Guadeloupe">Guadeloupe</option>
        <option value="Guam">Guam</option>
        <option value="Guatemala">Guatemala</option>
        <option value="Guernsey">Guernsey</option>
        <option value="Guinea">Guinea</option>
        <option value="Guinea-bissau">Guinea-bissau</option>
        <option value="Guyana">Guyana</option>
        <option value="Haiti">Haiti</option>
        <option value="Heard Island and Mcdonald Islands">Heard Island and Mcdonald Islands</option>
        <option value="Holy See (Vatican City State)">Holy See (Vatican City State)</option>
        <option value="Honduras">Honduras</option>
        <option value="Hong Kong">Hong Kong</option>
        <option value="Hungary">Hungary</option>
        <option value="Iceland">Iceland</option>
        <option value="India">India</option>
        <option value="Indonesia">Indonesia</option>
        <option value="Iran, Islamic Republic of">Iran, Islamic Republic of</option>
        <option value="Iraq">Iraq</option>
        <option value="Ireland">Ireland</option>
        <option value="Isle of Man">Isle of Man</option>
        <option value="Israel">Israel</option>
        <option value="Italy">Italy</option>
        <option value="Jamaica">Jamaica</option>
        <option value="Japan">Japan</option>
        <option value="Jersey">Jersey</option>
        <option value="Jordan">Jordan</option>
        <option value="Kazakhstan">Kazakhstan</option>
        <option value="Kenya">Kenya</option>
        <option value="Kiribati">Kiribati</option>
        <option value="Korea, Democratic People's Republic of">Korea, Democratic People's Republic of</option>
        <option value="Korea, Republic of">Korea, Republic of</option>
        <option value="Kuwait">Kuwait</option>
        <option value="Kyrgyzstan">Kyrgyzstan</option>
        <option value="Lao People's Democratic Republic">Lao People's Democratic Republic</option>
        <option value="Latvia">Latvia</option>
        <option value="Lebanon">Lebanon</option>
        <option value="Lesotho">Lesotho</option>
        <option value="Liberia">Liberia</option>
        <option value="Libyan Arab Jamahiriya">Libyan Arab Jamahiriya</option>
        <option value="Liechtenstein">Liechtenstein</option>
        <option value="Lithuania">Lithuania</option>
        <option value="Luxembourg">Luxembourg</option>
        <option value="Macao">Macao</option>
        <option value="Macedonia, The Former Yugoslav Republic of">Macedonia, The Former Yugoslav Republic of</option>
        <option value="Madagascar">Madagascar</option>
        <option value="Malawi">Malawi</option>
        <option value="Malaysia">Malaysia</option>
        <option value="Maldives">Maldives</option>
        <option value="Mali">Mali</option>
        <option value="Malta">Malta</option>
        <option value="Marshall Islands">Marshall Islands</option>
        <option value="Martinique">Martinique</option>
        <option value="Mauritania">Mauritania</option>
        <option value="Mauritius">Mauritius</option>
        <option value="Mayotte">Mayotte</option>
        <option value="Mexico">Mexico</option>
        <option value="Micronesia, Federated States of">Micronesia, Federated States of</option>
        <option value="Moldova, Republic of">Moldova, Republic of</option>
        <option value="Monaco">Monaco</option>
        <option value="Mongolia">Mongolia</option>
        <option value="Montenegro">Montenegro</option>
        <option value="Montserrat">Montserrat</option>
        <option value="Morocco">Morocco</option>
        <option value="Mozambique">Mozambique</option>
        <option value="Myanmar">Myanmar</option>
        <option value="Namibia">Namibia</option>
        <option value="Nauru">Nauru</option>
        <option value="Nepal">Nepal</option>
        <option value="Netherlands">Netherlands</option>
        <option value="Netherlands Antilles">Netherlands Antilles</option>
        <option value="New Caledonia">New Caledonia</option>
        <option value="New Zealand">New Zealand</option>
        <option value="Nicaragua">Nicaragua</option>
        <option value="Niger">Niger</option>
        <option value="Nigeria">Nigeria</option>
        <option value="Niue">Niue</option>
        <option value="Norfolk Island">Norfolk Island</option>
        <option value="Northern Mariana Islands">Northern Mariana Islands</option>
        <option value="Norway">Norway</option>
        <option value="Oman">Oman</option>
        <option value="Pakistan">Pakistan</option>
        <option value="Palau">Palau</option>
        <option value="Palestinian Territory, Occupied">Palestinian Territory, Occupied</option>
        <option value="Panama">Panama</option>
        <option value="Papua New Guinea">Papua New Guinea</option>
        <option value="Paraguay">Paraguay</option>
        <option value="Peru">Peru</option>
        <option value="Philippines">Philippines</option>
        <option value="Pitcairn">Pitcairn</option>
        <option value="Poland">Poland</option>
        <option value="Portugal">Portugal</option>
        <option value="Puerto Rico">Puerto Rico</option>
        <option value="Qatar">Qatar</option>
        <option value="Reunion">Reunion</option>
        <option value="Romania">Romania</option>
        <option value="Russian Federation">Russian Federation</option>
        <option value="Rwanda">Rwanda</option>
        <option value="Saint Helena">Saint Helena</option>
        <option value="Saint Kitts and Nevis">Saint Kitts and Nevis</option>
        <option value="Saint Lucia">Saint Lucia</option>
        <option value="Saint Pierre and Miquelon">Saint Pierre and Miquelon</option>
        <option value="Saint Vincent and The Grenadines">Saint Vincent and The Grenadines</option>
        <option value="Samoa">Samoa</option>
        <option value="San Marino">San Marino</option>
        <option value="Sao Tome and Principe">Sao Tome and Principe</option>
        <option value="Saudi Arabia">Saudi Arabia</option>
        <option value="Senegal">Senegal</option>
        <option value="Serbia">Serbia</option>
        <option value="Seychelles">Seychelles</option>
        <option value="Sierra Leone">Sierra Leone</option>
        <option value="Singapore">Singapore</option>
        <option value="Slovakia">Slovakia</option>
        <option value="Slovenia">Slovenia</option>
        <option value="Solomon Islands">Solomon Islands</option>
        <option value="Somalia">Somalia</option>
        <option value="South Africa">South Africa</option>
        <option value="South Georgia and The South Sandwich Islands">South Georgia and The South Sandwich Islands</option>
        <option value="Spain">Spain</option>
        <option value="Sri Lanka">Sri Lanka</option>
        <option value="Sudan">Sudan</option>
        <option value="Suriname">Suriname</option>
        <option value="Svalbard and Jan Mayen">Svalbard and Jan Mayen</option>
        <option value="Swaziland">Swaziland</option>
        <option value="Sweden">Sweden</option>
        <option value="Switzerland">Switzerland</option>
        <option value="Syrian Arab Republic">Syrian Arab Republic</option>
        <option value="Taiwan">Taiwan</option>
        <option value="Tajikistan">Tajikistan</option>
        <option value="Tanzania, United Republic of">Tanzania, United Republic of</option>
        <option value="Thailand">Thailand</option>
        <option value="Timor-leste">Timor-leste</option>
        <option value="Togo">Togo</option>
        <option value="Tokelau">Tokelau</option>
        <option value="Tonga">Tonga</option>
        <option value="Trinidad and Tobago">Trinidad and Tobago</option>
        <option value="Tunisia">Tunisia</option>
        <option value="Turkey">Turkey</option>
        <option value="Turkmenistan">Turkmenistan</option>
        <option value="Turks and Caicos Islands">Turks and Caicos Islands</option>
        <option value="Tuvalu">Tuvalu</option>
        <option value="Uganda">Uganda</option>
        <option value="Ukraine">Ukraine</option>
        <option value="United Arab Emirates">United Arab Emirates</option>
        <option value="United Kingdom">United Kingdom</option>
        <option value="United States">United States</option>
        <option value="United States Minor Outlying Islands">United States Minor Outlying Islands</option>
        <option value="Uruguay">Uruguay</option>
        <option value="Uzbekistan">Uzbekistan</option>
        <option value="Vanuatu">Vanuatu</option>
        <option value="Venezuela">Venezuela</option>
        <option value="Viet Nam">Viet Nam</option>
        <option value="Virgin Islands, British">Virgin Islands, British</option>
        <option value="Virgin Islands, U.S.">Virgin Islands, U.S.</option>
        <option value="Wallis and Futuna">Wallis and Futuna</option>
        <option value="Western Sahara">Western Sahara</option>
        <option value="Yemen">Yemen</option>
        <option value="Zambia">Zambia</option>
        <option value="Zimbabwe">Zimbabwe</option>
    </select>
    <input type="email" name="email" placeholder="Email Address" required><br>
    <input type="text" name="phone" placeholder="Phone Number" required><br>
    <input type="text" name="notes" placeholder="Additional Notes (optional)"><br>
    <?php
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
