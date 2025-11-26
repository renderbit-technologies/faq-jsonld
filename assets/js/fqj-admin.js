jQuery(document).ready(function ($) {
  var $assocType = $("#fqj_assoc_type");
  var $container = $("#fqj_assoc_container");

  function renderField(type, data) {
    $container.empty();
    if (type === "urls") {
      var urls = data.urls ? data.urls.join("\n") : "";
      $container.append(
        "<p>Enter one full URL per line (e.g. https://example.com/about/)</p>"
      );
      $container.append(
        '<textarea id="fqj_assoc_urls" name="fqj_assoc_urls" rows="6" style="width:100%;">' +
          urls +
          "</textarea>"
      );
    } else if (type === "posts") {
      $container.append("<p>Search posts/pages and add multiple results.</p>");
      $container.append(
        '<select id="fqj_assoc_posts_select" name="fqj_assoc_posts_select[]" multiple="multiple" style="width:100%"></select>'
      );
      $("#fqj_assoc_posts_select").select2({
        placeholder: "Search posts by title...",
        ajax: {
          url: fqjAdmin.ajax_url,
          dataType: "json",
          delay: 250,
          data: function (params) {
            return {
              q: params.term,
              action: "fqj_search_posts",
              nonce: fqjAdmin.nonce
            };
          },
          processResults: function (data) {
            return { results: data };
          },
          cache: true
        },
        minimumInputLength: 1,
        templateResult: function (item) {
          if (!item.id) return item.text;
          return item.text;
        },
        templateSelection: function (item) {
          return item.text;
        }
      });

      if (data.posts && data.posts.length) {
        var select = $("#fqj_assoc_posts_select");
        data.posts.forEach(function (p) {
          var option = new Option(p.text, p.id, true, true);
          select.append(option);
        });
        select.trigger("change");
      }
    } else if (type === "post_types") {
      $container.append(
        '<p>Select post type(s) to associate this FAQ with.</p><div id="fqj_post_types_wrap"></div>'
      );
      $.get(
        fqjAdmin.ajax_url,
        { action: "fqj_get_post_types", nonce: fqjAdmin.nonce },
        function (resp) {
          if (!resp || !resp.length) return;
          var wrap = $("#fqj_post_types_wrap");
          resp.forEach(function (pt) {
            var checked =
              data.post_types && data.post_types.indexOf(pt.name) !== -1
                ? "checked"
                : "";
            wrap.append(
              '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="fqj_assoc_post_types[]" value="' +
                pt.name +
                '" ' +
                checked +
                "> " +
                pt.label +
                "</label>"
            );
          });
        },
        "json"
      );
    } else if (type === "tax_terms") {
      $container.append(
        "<p>Search taxonomy terms and add multiple results.</p>"
      );
      $container.append(
        '<select id="fqj_assoc_terms_select" name="fqj_assoc_terms_select[]" multiple="multiple" style="width:100%"></select>'
      );
      $("#fqj_assoc_terms_select").select2({
        placeholder: "Search terms by name...",
        ajax: {
          url: fqjAdmin.ajax_url,
          dataType: "json",
          delay: 250,
          data: function (params) {
            return {
              q: params.term,
              action: "fqj_search_terms",
              nonce: fqjAdmin.nonce
            };
          },
          processResults: function (data) {
            return { results: data };
          },
          cache: true
        },
        minimumInputLength: 1,
        templateResult: function (item) {
          return item.text;
        },
        templateSelection: function (item) {
          return item.text;
        }
      });
      if (data.terms && data.terms.length) {
        var select = $("#fqj_assoc_terms_select");
        data.terms.forEach(function (t) {
          var option = new Option(t.text, t.id, true, true);
          select.append(option);
        });
        select.trigger("change");
      }
    } else if (type === "global") {
      $container.append("<p>This FAQ will be included site-wide.</p>");
      $container.append(
        '<input type="hidden" name="fqj_assoc_global" value="1">'
      );
    }
  }

  // preload data
  var initData = {};
  try {
    var raw = $("#fqj_assoc_data").val();
    if (raw) initData = JSON.parse(raw);
  } catch (e) {
    initData = {};
  }

  renderField($assocType.val(), initData);

  $assocType.on("change", function () {
    renderField($(this).val(), {});
  });
});
