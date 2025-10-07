<?php
session_start();
$db=mysqli_connect("localhost","root","","transforshop");
$shop_items=mysqli_query($db,"SELECT * FROM items ORDER BY stock DESC;");

function addToCart($itemId) {
    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    if (isset($_SESSION['cart'][$itemId])) $_SESSION['cart'][$itemId]++;
    else $_SESSION['cart'][$itemId] = 1;
}

function removeFromCart($itemId) {
    if (isset($_SESSION['cart'][$itemId])) {
        $_SESSION['cart'][$itemId]--;
        if ($_SESSION['cart'][$itemId] <= 0) unset($_SESSION['cart'][$itemId]);
    }
}

function setCartQuantity($itemId, $quantity, $maxStock) {
    $quantity = max(0, min($quantity, $maxStock));
    if ($quantity > 0) $_SESSION['cart'][$itemId] = $quantity;
    else unset($_SESSION['cart'][$itemId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_to_cart_id'])) {
        $itemId = intval($_POST['add_to_cart_id']);
        addToCart($itemId);
    }
    if (isset($_POST['add_one'])) {
        $itemId = intval($_POST['add_one']);
        addToCart($itemId);
    }
    if (isset($_POST['remove_one'])) {
        $itemId = intval($_POST['remove_one']);
        removeFromCart($itemId);
    }
    if (isset($_POST['set_quantity_id']) && isset($_POST['set_quantity_value'])) {
        $itemId = intval($_POST['set_quantity_id']);
        $quantity = intval($_POST['set_quantity_value']);
        // Get max stock for this item
        $item_query = mysqli_query($db, "SELECT stock FROM items WHERE id=$itemId;");
        $maxStock = 0;
        if ($item_row = mysqli_fetch_array($item_query)) $maxStock = $item_row['stock'];
        setCartQuantity($itemId, $quantity, $maxStock);
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TransforMate Official Shop</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div id="navbar">
    <h1>TransforMate Official Shop</h1>
    <div id="cart-container">
        <svg id="shopping-cart" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.1.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path opacity=".4" d="M124.8 96L171.3 352L474.9 352C505.7 352 532.1 330.1 537.8 299.8L568.9 133.9C572.6 114.2 557.5 96 537.4 96L124.8 96z"/><path d="M24 48C10.7 48 0 58.7 0 72C0 85.3 10.7 96 24 96L69.3 96C73.2 96 76.5 98.8 77.2 102.6L129.3 388.9C135.5 423.1 165.3 448 200.1 448L456 448C469.3 448 480 437.3 480 424C480 410.7 469.3 400 456 400L200.1 400C188.5 400 178.6 391.7 176.5 380.3L124.4 94C119.6 67.4 96.4 48 69.3 48L24 48zM256 528C256 501.5 234.5 480 208 480C181.5 480 160 501.5 160 528C160 554.5 181.5 576 208 576C234.5 576 256 554.5 256 528zM480 528C480 501.5 458.5 480 432 480C405.5 480 384 501.5 384 528C384 554.5 405.5 576 432 576C458.5 576 480 554.5 480 528z"/></svg>
        <?php
        $items_in_cart = 0;
        if (isset($_SESSION['cart'])) foreach ($_SESSION['cart'] as $key => $value) $items_in_cart += $value;
        echo "<h3>Shopping Cart ($items_in_cart)</h3>"
        ?>
        <div id="cart-dropdown">
            <?php
            if ($items_in_cart == 0) echo "<p>Your cart is currently empty.</p>";
            else {
                echo "<table>";
                echo "<tr><th>Item</th><th>Quantity</th><th>Price</th><th>Actions</th></tr>";
                $total_price = 0;
                foreach ($_SESSION['cart'] as $itemId => $quantity) {
                    $item_query = mysqli_query($db, "SELECT * FROM items WHERE id=$itemId;");
                    if ($item_row = mysqli_fetch_array($item_query)) {
                        $item_price = number_format($item_row['price'] * $quantity, 2);
                        $total_price += $item_row['price'] * $quantity;
                        echo "<tr>";
                        echo "<td>{$item_row['name']}</td>";
                        echo "<td>";
                        echo "<form method='POST' style='display:inline-flex; align-items:center; gap:2px;' class='cart-quantity-form' onsubmit='return handleCartQuantityFormSubmit(event, this)'>";
                        echo "<button type='button' onclick='updateCartQuantity(this.form, -1)' style='width:28px;height:28px;'>-</button>";
                        echo "<input type='number' name='set_quantity_value' value='$quantity' min='1' max='{$item_row['stock']}' style='width:40px; text-align:center;' onchange='this.form.submit()'>";
                        echo "<input type='hidden' name='set_quantity_id' value='$itemId'>";
                        echo "<button type='button' onclick='updateCartQuantity(this.form, 1)' style='width:28px;height:28px;'>+</button>";
                        echo "</form> ";
                        echo "</td>";
                        echo "<td>{$item_price}€</td>";
                        echo "<td>";
                        echo "<form method='POST' style='display:inline;'>";
                        echo "<input type='hidden' name='set_quantity_id' value='$itemId'>";
                        echo "<input type='hidden' name='set_quantity_value' value='0'>";
                        echo "<button id='remove-button' type='submit'>Remove</button>";
                        echo "</form>";
                        echo "</td>";
                        echo "</tr>";
                    }
                }
                $total_price = number_format($total_price, 2);
                echo "<tr><td colspan='3'><strong>Total</strong></td><td><strong>{$total_price}€</strong></td></tr>";
                echo "</table>";
                echo "<form method='POST' action='checkout.php'><button id='checkout-button' type='submit'>Checkout</button></form>";
            }
            ?>
        </div>
    </div>
</div>

<div id="product-list">
    <?php
    while ($row = mysqli_fetch_array($shop_items)) {
        if (!$row['visible']) continue;
        $price=number_format($row['price'],2);
        if ($row['stock'] > 0) {
            echo "<div class='product-card'>";
            if ($row['preorder']) echo "<h1 class='preorder-label'>Preorder</h1>";
            echo "<img src='{$row['image']}' alt='Product Image'>";
            echo "<h2>{$row['name']}</h2>";
            echo "<p>Stock: {$row['stock']}</p>";
            echo "<p>{$price}€</p>";
            echo "<form method='POST' style='display:inline;'>";
            echo "<input type='hidden' name='add_to_cart_id' value='{$row['id']}'>";
            echo "<button class='add-to-cart' type='submit'>Add to Cart</button>";
            echo "</form>";
        } else {
            echo "<div class='product-card out-of-stock'>";
            echo "<h1 class='out-of-stock-label'>Out of Stock</h1>";
            echo "<img src='{$row['image']}' alt='Product Image'>";
            echo "<h2>{$row['name']}</h2>";
            echo "<p>{$price}€</p>";
            echo "<button class='add-to-cart' disabled>Out of Stock</button>";
        }
        echo "</div>";
    }
    ?>
</div>
<script>
function updateCartQuantity(form, delta) {
    const input = form.querySelector('input[name="set_quantity_value"]');
    const max = parseInt(input.max) || 9999;
    let val = parseInt(input.value) || (parseInt(input.min) || 1);
    val += delta;
    if (val > max) val = max;
    input.value = val;
    form.submit();
}
// Prevent double submit if JS triggers submit
function handleCartQuantityFormSubmit(e, form) { return true; }
</script>
</body>
</html>
