$ShopName

<%t SilverShop\AbandonedCart.EmailHeading "You left something in your cart" %>

<%t SilverShop\AbandonedCart.EmailIntro "Your cart is still here. Pick up right where you left off — we've saved your items for you." %>

<% loop $Items %>- $Title ($Quantity) — $LineTotal.Nice
<% end_loop %>
<%t SilverShop\AbandonedCart.CartTotal "Cart total" %>: $CartTotal.Nice

<%t SilverShop\AbandonedCart.EmailButton "Return to your cart" %>: $ResumeLink

<%t SilverShop\AbandonedCart.EmailUnsubIntro "You received this because you left items in your cart at our store." %> $UnsubscribeLink
