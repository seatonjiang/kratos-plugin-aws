jQuery(function ($) {
  function runAjaxAction(button, action) {
    button = $(button);
    var panel = button.closest(".debug-bar-panel-v5");
    var responseBox = button.closest("td").find(".ajax-response");

    responseBox.text("Processing...").show();
    $.post(
      ajaxurl,
      {
        action: action,
        uid: panel.data("uid"),
        _wpnonce: panel.data("nonce"),
      },
      function (data) {
        //The response contains HTML that should already be escaped in server-side code.
        //phpcs:ignore WordPressVIPMinimum.JS.HTMLExecutingFunctions.html
        responseBox.html(data);
      },
      "html"
    );
  }

  $('.debug-bar-panel-v5 input[name="check-now-button"]').on(
    "click",
    function () {
      runAjaxAction(this, "v5_debug_check_now");
      return false;
    }
  );

  $('.debug-bar-panel-v5 input[name="request-info-button"]').on(
    "click",
    function () {
      runAjaxAction(this, "v5_debug_request_info");
      return false;
    }
  );

  // Debug Bar uses the panel class name as part of its link and container IDs. This means we can
  // end up with multiple identical IDs if more than one plugin uses the update checker library.
  // Fix it by replacing the class name with the plugin slug.
  var panels = $("#debug-menu-targets").find(".debug-bar-panel-v5");
  panels.each(function () {
    var panel = $(this);
    var uid = panel.data("uid");
    var target = panel.closest(".debug-menu-target");

    //Change the panel wrapper ID.
    target.attr("id", "debug-menu-target-" + uid);

    //Change the menu link ID as well and point it at the new target ID.
    $("#debug-bar-menu")
      .find(".debug-menu-link-" + uid)
      .closest(".debug-menu-link")
      .attr("id", "debug-menu-link-" + uid)
      .attr("href", "#" + target.attr("id"));
  });
});
