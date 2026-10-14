<!DOCTYPE html>
<html lang="$ContentLocale">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$Title.XML &middot; $ShopName.XML</title>
    <style>
        body{margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#333;line-height:1.5}
        .wrap{max-width:560px;margin:48px auto;background:#fff;border-radius:8px;padding:32px 36px}
        h1{margin:0 0 12px;font-size:22px}
        a.btn{display:inline-block;margin-top:16px;background:#222;color:#fff;text-decoration:none;padding:10px 20px;border-radius:4px}
    </style>
</head>
<body>
    <div class="wrap">
        <% if $IsUnsubscribed %>
            <h1><%t SilverShop\AbandonedCart.PageUnsubscribed "You've been unsubscribed" %></h1>
            <p><%t SilverShop\AbandonedCart.PageUnsubscribedMsg "You won't receive any more cart-recovery emails." %></p>
            <% if $CartLink %><a class="btn" href="$CartLink"><%t SilverShop\AbandonedCart.PageToCart "Go to your cart" %></a><% end_if %>
        <% else %>
            <h1><%t SilverShop\AbandonedCart.PageInvalid "This link is not valid" %></h1>
            <p><%t SilverShop\AbandonedCart.PageInvalidMsg "This recovery or unsubscribe link has expired or is incorrect." %></p>
        <% end_if %>
    </div>
</body>
</html>
