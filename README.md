# TransforShop
The merchandising shop for the TransforMate project, FOSS to keep on theme with my very obvious addiction to sharing my code.

# How to use
Just set up an apache web server with MySQL, and fill out a `secrets.php` file with:
```
<?php
const DB_HOST='localhost';
const DB_USER='username';
const DB_PASS='password';
const DB_DATABASE='transforshop';
const STRIPE_KEY = 'stripe_api_key';
const STRIPE_WEBHOOK_KEY = 'stripe_webhook_key';
```
And then you're goood to go. It's that easy (excluding creating the databases :3c)
