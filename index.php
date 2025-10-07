<?php
$db=mysqli_connect("localhost","root","","transforshop");
$shop_items=mysqli_query($db,"SELECT * FROM items");
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
        <h3>Shopping Cart</h3>
    </div>
</div>

<div id="product-list">
    <?php
    while ($row = mysqli_fetch_array($shop_items)) {
        $price=number_format($row['price'],2);
        echo "<div class='product-card'>";
        echo "<img src='{$row['image']}' alt='Product Image'>";
        echo "<h2>{$row['name']}</h2>";
        echo "<p>{$price}€</p>";
        echo "<button class='add-to-cart'>Add to Cart</button>";
        echo "</div>";
    }
    ?>
</div>
</body>
</html>
