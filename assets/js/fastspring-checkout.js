/* globals jQuery, woocommerce_fastspring_params, fastspring, wc_checkout_params */
var checkoutForm = jQuery('form.checkout')

function setLoadingDone () {
  checkoutForm.removeClass('processing').unblock()
}

function setLoadingOn () {
  checkoutForm.addClass('processing').block({
    message: null,
    overlayCSS: {
      background: '#fff',
      opacity: 0.6
    }
  })
}

// Debug function
function dataCallbackFunction (data) { // eslint-disable-line no-unused-vars
  console.log('dataCallbackFunction:', data)
}

// Debug function
function errorCallback (code, string) { // eslint-disable-line no-unused-vars
  console.log('errorCallback: ', code, string)
}

// Get AJAX Url
function getAjaxURL (endpoint) {
  return woocommerce_fastspring_params.ajax_url.toString().replace('%%endpoint%%', 'wc_fastspring_' + endpoint)
}

// Before FS request handler
function fastspringBeforeRequestHandler () { // eslint-disable-line no-unused-vars
  setLoadingDone()
}

// FS Popup close handler - redirect to receipt page if valid
function fastspringPopupCloseHandler(data) { // eslint-disable-line no-unused-vars
  clearTimeout(fastspringPopupTimeout); // Clear the timeout
  jQuery.ajax({
    type: 'POST',
    url: woocommerce_params.ajax_url,
    data: {
      nonce: woocommerce_fastspring_params.nonce.create_actual_order,
      form_data: checkoutForm.serialize(),
      action: 'wc_fs_create_actual_order',
      fastspring_order_id: data.reference
    },
    dataType: 'json',
    success: function (response) {
      try {
        if (response.data.result === 'success') {
          // data.reference is the FS order ID - only returned on payment instead of just closing modal
          if (data && data.reference) {
            requestPaymentCompletionUrl(data || {}, function (err, res) {
              console.log('requestPaymentCompletionUrl', err, res);
              if (!err) {
                window.location = res.redirect_url
              } else {
                throw new Error(err)
              }
            });
          } else {
            throw new Error('No reference found in data on wc_fs_create_actual_order action');
          }
        } else if (response.data.result === 'failure') {
          throw new Error('Result failure')
        } else {
          throw new Error('Invalid response')
        }
      } catch (err) {
        // Reload page
        if (response.data.reload === true) {
          window.location.reload()
          return
        }
        // Trigger update in case we need a fresh nonce
        if (response.data.refresh === true) {
          jQuery(document.body).trigger('update_checkout')
        }
        // Add new errors
        if (response.data.messages) {
          submitError(response.data.messages)
        } else {
          submitError('<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>')
        }

        // Log error to console
        console.error( 'Checkout Error:', response );
      }
    },
    error: function (jqXHR, textStatus, errorThrown) {
      submitError('<div class="woocommerce-error">' + errorThrown + '</div>')
    }
  })
}

// AJAX call to get odrer payment page for receipt and potentially mark order as complete usign FS API
function requestPaymentCompletionUrl (data, cb) { // eslint-disable-line no-unused-vars
  data.security = woocommerce_fastspring_params.nonce.receipt
  jQuery.ajax({
    type: 'POST',
    dataType: 'json',
    data: JSON.stringify(data),
    url: getAjaxURL('get_receipt'),
    success: function (response) {
      cb(null, response)
    },
    error: function (xhr, err, e) {
      cb(xhr.responseText)
    }
  })
}

// Define timeout duration (e.g., 24 hours)
const FASTSPRING_POPUP_TIMEOUT = woocommerce_fastspring_params.popup_timeout || 60 * 1000 * 60 * 24; // 24 hours in milliseconds
let fastspringPopupTimeout;

// Function to handle popup timeout
function handleFastSpringPopupTimeout() {
  console.warn('FastSpring popup session has timed out.');
  alert('Your session has timed out. The page will be refreshed.');
  // Reload the page
  window.location.reload();
}

// Launch FS (popup or redirect)
function launchFastSpring (session) {
  console.log(session)
  fastspring.builder.secure(session.payload, session.key)
  fastspring.builder.checkout();

  // Clear any existing timeout and set a new one
  clearTimeout(fastspringPopupTimeout);
  fastspringPopupTimeout = setTimeout(handleFastSpringPopupTimeout, FASTSPRING_POPUP_TIMEOUT);
}
function unserialize(serializedData) {
  let urlParams = new URLSearchParams(serializedData); // get interface / iterator
  let unserializedData = {}; // prepare result object
  for (let [key, value] of urlParams) { // get pair > extract it to key/value
      unserializedData[key] = value;
  }

  return unserializedData;
}

// Create order and return payload for FS
function setTempOrder() {
  jQuery.ajax({
    type: 'POST',
    // url: wc_checkout_params.checkout_url, // Ensure this endpoint doesn't create an order
    url: woocommerce_params.ajax_url,
    data: {
      nonce: woocommerce_fastspring_params.nonce.receipt,
      form_data: checkoutForm.serialize(),
      action: 'wc_fs_create_temp_order',
    },
    dataType: 'json',
    success: function(response) {
      try {
        if (response.data.result === 'success') {
          if (response.data.temp_order_data.temp_order_nonce) {
            woocommerce_fastspring_params.nonce.create_actual_order = response.data.temp_order_data.temp_order_nonce;
          }
          launchFastSpring(response.data.session);
        } else if (response.data.result === 'failure') {
          throw new Error('Result failure');
        } else if (response.data.result === 'error') {
          submitError(response.data.messages);
        } else {
          throw new Error('Invalid response');
        }
      } catch (err) {
        // Reload page
        if (response.reload === true) {
          window.location.reload();
          return;
        }
        // Trigger update in case we need a fresh nonce
        if (response.refresh === true) {
          jQuery(document.body).trigger('update_checkout');
        }
        // Add new errors
        if (response.messages) {
          submitError(response.messages);
        } else {
          submitError('<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>');
        }
      }
    },
    error: function(jqXHR, textStatus, errorThrown) {
      submitError('<div class="woocommerce-error">' + errorThrown + '</div>');
    }
  });
}
// Checkout form handler - create order and launch FS
function doSubmit () {
  setLoadingOn()
  setTempOrder();
}
// Error handler
function submitError (errorMessage) {
  setLoadingDone()
  jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove()

  // Check if messages is an array
  if (Array.isArray(errorMessage)) {
    var errorList = '<ul class="woocommerce-error wc-fs-error-list">';
    errorMessage.forEach(function(message) {
        errorList += '<li>' + message + '</li>';
    });
    errorList += '</ul>';
    // Display the concatenated error messages as a single WooCommerce notice
    checkoutForm.prepend(errorList);
  } else {
    // Display a single error message
    checkoutForm.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + errorMessage + '</div>')
  }

  checkoutForm.removeClass('processing')
  checkoutForm.find('.input-text, select, input:checkbox').trigger('validate').blur()
  jQuery('html, body').animate({
    scrollTop: (jQuery('form.checkout').offset().top - 100)
  }, 1000)
  jQuery(document.body).trigger('checkout_error')
}
// Check if FS is selected
function isFastSpringSelected () {
  return jQuery('.woocommerce-checkout input[name="payment_method"]:checked').attr('id') === 'payment_method_fastspring'
}
checkoutForm.on('click', jQuery('#place_order'), function(e){
  if (isFastSpringSelected()) {
    e.stopImmediatePropagation();
    e.preventDefault();
    doSubmit()
    return false
  }
})

// Attach submit event if FS is selected
checkoutForm.on('checkout_place_order', function () {
  window.location = res.redirect_url;
});