<%-- Abandoned-cart recovery email. Wraps its body in core 6.2's shared flat email shell (OrderEmailHeader /
     OrderEmailFooter), so it inherits the shop's transactional-email look for free. The body supplies its own
     table rows between the two includes, exactly like core's Order_ReceiptEmail. --%>
<% include SilverShop\Includes\OrderEmailHeader Modifier="cart-recovery" %>
                        <tr>
                            <th class="silvershop-email__title-cell" scope="col" colspan="2">
                                <% if $SiteConfig.ShopEmailBrand %>$SiteConfig.ShopEmailBrand<% else %><span class="silvershop-email__brand">$ShopName.XML</span><% end_if %>
                                <h1 class="silvershop-email__title"><%t SilverShop\AbandonedCart.EmailHeading "You left something in your cart" %></h1>
                            </th>
                        </tr>
                        <tr>
                            <td class="silvershop-email__intro silvershop-typography" colspan="2">
                                <p><%t SilverShop\AbandonedCart.EmailIntro "Your cart is still here. Pick up right where you left off — we've saved your items for you." %></p>
                                <p><a class="silvershop-email__btn" href="$ResumeLink"><%t SilverShop\AbandonedCart.EmailButton "Return to your cart" %></a></p>
                            </td>
                        </tr>
                        <% if $Items %>
                        <tr>
                            <td class="silvershop-email__order" colspan="2">
                                <table class="silvershop-receipt" cellpadding="0" cellspacing="0" border="0">
                                    <thead>
                                        <tr>
                                            <th class="silvershop-receipt__cell silvershop-receipt__cell--head silvershop-receipt__cell--left" scope="col"><%t SilverShop\AbandonedCart.ColProduct "Product" %></th>
                                            <th class="silvershop-receipt__cell silvershop-receipt__cell--head silvershop-receipt__cell--center" scope="col"><%t SilverShop\AbandonedCart.ColQty "Qty" %></th>
                                            <th class="silvershop-receipt__cell silvershop-receipt__cell--head silvershop-receipt__cell--right" scope="col"><%t SilverShop\AbandonedCart.ColPrice "Price" %></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <% loop $Items %>
                                        <tr>
                                            <td class="silvershop-receipt__cell silvershop-receipt__cell--left">$Title.XML</td>
                                            <td class="silvershop-receipt__cell silvershop-receipt__cell--center">$Quantity</td>
                                            <td class="silvershop-receipt__cell silvershop-receipt__cell--right">$LineTotal.Nice</td>
                                        </tr>
                                        <% end_loop %>
                                    </tbody>
                                    <tfoot>
                                        <tr class="silvershop-receipt__row--total">
                                            <td class="silvershop-receipt__cell silvershop-receipt__cell--left" colspan="2"><%t SilverShop\AbandonedCart.CartTotal "Cart total" %></td>
                                            <td class="silvershop-receipt__cell silvershop-receipt__cell--right">$CartTotal.Nice</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </td>
                        </tr>
                        <% end_if %>
                        <tr>
                            <td class="silvershop-email__intro" colspan="2">
                                <p style="color:#888888;font-size:12px;line-height:1.5;">
                                    <%t SilverShop\AbandonedCart.EmailUnsubIntro "You received this because you left items in your cart at our store." %>
                                    <a href="$UnsubscribeLink"><%t SilverShop\AbandonedCart.Unsubscribe "unsubscribe" %></a>.
                                </p>
                            </td>
                        </tr>
<% include SilverShop\Includes\OrderEmailFooter %>
