<?php
require_once 'secrets.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_DATABASE);
if (!$db) die("Connection failed: " . mysqli_connect_error());
$shop_items=mysqli_query($db,"SELECT * FROM items ORDER BY stock DESC;");

function addToCart($itemId) {
    if (!isset($_SESSION['cart'][$itemId])) $_SESSION['cart'][$itemId] = 0;
    $_SESSION['cart'][$itemId]++;
}

function setCartQuantity($itemId, $quantity, $maxStock) {
    $quantity = min($quantity, $maxStock);
    $_SESSION['cart'][$itemId] = $quantity;
    if ($quantity <= 0) unset($_SESSION['cart'][$itemId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['set_quantity_id']) && isset($_POST['set_quantity_value'])) {
        $itemId = intval($_POST['set_quantity_id']);
        // Get max stock for this item
        $item_query = mysqli_query($db, "SELECT stock FROM items WHERE id=$itemId;");
        $item_row = mysqli_fetch_array($item_query);
        if (!$item_row) {
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        $maxStock = $item_row['preorders_left'] > 0 ? $item_row['preorders_left'] : $item_row['stock'];
        setCartQuantity($itemId, intval($_POST['set_quantity_value']), $maxStock);
    } else if (isset($_POST['add_to_cart_id'])) addToCart(intval($_POST['add_to_cart_id']));

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="The official storefront for the TransforMate project. Browse and purchase
                                      exclusive merchandise and products.">
    <meta name="keywords" content="TransforMate, Shop, Official Store, Merchandise, Products, Stickers">
    <meta name="author" content="Dory">
    <meta name="robots" content="index, follow">
    <title>TransforMate Official Shop | Shop</title>

    <link rel="canonical" href="https://shop.transformate.live/index.php">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php
echo "<div id='navbar'><h1>TransforMate Official Shop | Shop</h1><div id='cart-container'>";
echo "<svg id='shopping-cart' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 640 640'><path opacity='.4' d='M124.8 96L171.3 352L474.9 352C505.7 352 532.1 330.1 537.8 299.8L568.9 133.9C572.6 114.2 557.5 96 537.4 96L124.8 96z'/><path d='M24 48C10.7 48 0 58.7 0 72C0 85.3 10.7 96 24 96L69.3 96C73.2 96 76.5 98.8 77.2 102.6L129.3 388.9C135.5 423.1 165.3 448 200.1 448L456 448C469.3 448 480 437.3 480 424C480 410.7 469.3 400 456 400L200.1 400C188.5 400 178.6 391.7 176.5 380.3L124.4 94C119.6 67.4 96.4 48 69.3 48L24 48zM256 528C256 501.5 234.5 480 208 480C181.5 480 160 501.5 160 528C160 554.5 181.5 576 208 576C234.5 576 256 554.5 256 528zM480 528C480 501.5 458.5 480 432 480C405.5 480 384 501.5 384 528C384 554.5 405.5 576 432 576C458.5 576 480 554.5 480 528z'/></svg>";
$items_in_cart = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $key => $value) $items_in_cart += $value;
    echo "<h3>Shopping Cart ($items_in_cart)</h3><div id='cart-dropdown'>";
    echo "<table><tr><th>Item</th><th>Quantity</th><th>Price</th><th>Actions</th></tr>";
    $total_price = 0;
    foreach ($_SESSION['cart'] as $itemId => $quantity) {
        $item_query = mysqli_query($db, "SELECT * FROM items WHERE id=$itemId;");
        if ($item_row = mysqli_fetch_array($item_query)) {
            $total_price += $item_row['price'] * $quantity;
            $max_items = $item_row['stock'];
            if ($item_row['preorders_left'] > 0) $max_items = $item_row['preorders_left'];
            if ($quantity > $max_items) {
                setCartQuantity($itemId, $max_items, $max_items);
                $quantity = $max_items;
            }
            echo "<tr><td>" . htmlspecialchars($item_row['name']) . "</td><td>";
            echo "<form method='POST' class='cart-quantity-form' onsubmit='return true'>";
            echo "<button type='button' onclick='updateCartQuantity(this.form, -1)'>-</button>";
            echo "<input type='number' name='set_quantity_value' value='$quantity' min='1' max='$max_items' onchange='this.form.submit()'>";
            echo "<input type='hidden' name='set_quantity_id' value='$itemId'>";
            echo "<button type='button' onclick='updateCartQuantity(this.form, 1)'>+</button></form></td>";
            echo "<td>" . number_format($item_row['price'] * $quantity, 2) . "€</td>";
            echo "<td><form method='POST' style='display:inline;'>";
            echo "<input type='hidden' name='set_quantity_id' value='$itemId'>";
            echo "<input type='hidden' name='set_quantity_value' value='0'>";
            echo "<button id='remove-button' type='submit'>Remove</button></form></td></tr>";
        }
    } $total_price = number_format($total_price, 2);
    echo "<tr><td colspan='3'><strong>Total</strong></td><td><strong>{$total_price}€</strong></td></tr></table>";
    echo "<form method='POST' action='checkout.php'><button id='checkout-button' type='submit'>Checkout</button></form>";
} else echo "<h3>Shopping Cart (0)</h3><div id='cart-dropdown'><p>Your cart is currently empty.</p>";
echo "</div></div></div>";

// Display products
echo "<div id='product-list'>";
while ($row = mysqli_fetch_array($shop_items)) {
    if (!$row['visible']) continue;
    if ($row['stock'] <= 0) echo "<div class='product-card oos'><h2 class='oos-label'>Out of Stock</h2>";
    else echo "<div class='product-card'>";
    echo "<img src='{$row['image']}' alt='Product Image'>";
    echo "<h2>" . htmlspecialchars($row['name']) . "</h2><p>" . htmlspecialchars($row['description']) . "</p>";
    $stock = $row['stock'];
    $text = "Units";
    if ($row['preorders_left'] > 0) {
        $stock = $row['preorders_left'];
        $text = "Preorders";
    }
    if ($stock > 10) echo "<p>" . $text . " left: " . $stock . "</p>";
    else echo "<p style='color:red;'>" . "Only " . $stock . strtolower($text) . " left.</p>";
    echo "<p>" . number_format($row['price'], 2) . "€</p>";
    if ($row['stock'] > 0) {
        echo "<form method='POST' style='display:inline;'>";
        echo "<input type='hidden' name='add_to_cart_id' value='{$row['id']}'>";
        echo "<button class='add-to-cart' type='submit'>Add to Cart</button>";
    } else echo "<button class='add-to-cart' type='button' disabled>Out of Stock</button>";
    echo "</form></div>";
} echo "</div>";
?>
<script>
function updateCartQuantity(form, delta) {
    const input = form.querySelector('input[name="set_quantity_value"]');
    input.value = Math.min((parseInt(input.value) || (parseInt(input.min) || 1)) + delta, parseInt(input.max) || 9999);
    form.submit();
}
</script>
</body>
</html>
