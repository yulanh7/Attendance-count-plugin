/* =========================================================
 * Attendance Plugin - main.js
 * =======================================================*/
jQuery(function ($) {
  const AP = (window.AP = window.AP || {});
  const S = {
    tableBox: "#filter-table-response",
    loaderBox: "#loader-box",
    form: "#es_attendance_form",
    modal: "#attendance-info-modal",
    modalContent: "#attendance-info-modal-content",
  };

  const storage = {
    get(k, d = null) { try { const raw = localStorage.getItem(k); return raw ? JSON.parse(raw) : d; } catch { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { } },
    del(k) { try { localStorage.removeItem(k); } catch { } },
  };

  const api = {
    post(action, payload = {}) {
      return $.ajax({ url: esAjax.ajaxurl, type: "POST", dataType: "json", data: { action, nonce: esAjax.nonce, ...payload } })
        .fail((xhr) => { const msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || xhr.responseText || "Network error"; console.error("AJAX error:", msg); });
    },
    postRaw(action, payload = {}) {
      return $.ajax({ url: esAjax.ajaxurl, type: "POST", data: { action, nonce: esAjax.nonce, ...payload } })
        .fail((xhr) => { console.error("AJAX raw error:", xhr && xhr.responseText); });
    },
  };

  function feScrollToContainerTop($c, offset = 10) {
    const top = $c.offset() ? $c.offset().top : 0;
    $("html, body").stop(true).animate({ scrollTop: Math.max(top - offset, 0) }, 300);
  }

  function showLoader(show) {
    const $b = $(S.loaderBox);
    if (!$b.length) return;
    show ? $b.show() : $b.hide();
  }

  function displayMessage($after, message, color) {
    $(".es-message").remove();
    $("<div>", { class: "es-message", text: message, css: { background: color, color: "#fff", padding: "8px", "margin-top": "10px", "border-radius": "5px" } }).insertAfter($after);
  }

  /* ========== 前台表单 ========== */
  function initForm() {
    const $form = $(S.form);
    if (!$form.length) return;

    const saved = storage.get("es_attendance_form_data", {});
    $form.find("input[name=es_first_name]").val(saved.es_first_name || "");
    $form.find("input[name=es_last_name]").val(saved.es_last_name || "");
    $form.find("input[name=es_email]").val(saved.es_email || "");
    $form.find("select[name=es_phone_country_code]").val(saved.es_phone_country_code || "+61");
    $form.find("input[name=es_phone_number]").val(saved.es_phone_number || "");
    $form.find("select[name=es_fellowship]").val(saved.es_fellowship || "");

    // 🔽 新增：手机号一致性与提示逻辑
    const $cc = $form.find("select[name=es_phone_country_code]");
    const $num = $form.find("input[name=es_phone_number]");

    function normalizePhone(cc, num) {
      let n = (num || "").replace(/[\s\-()]/g, "");
      if (cc === "+61" && n.charAt(0) === "0") n = n.slice(1);
      return (cc || "") + n;
    }

    const savedPhone = normalizePhone(saved.es_phone_country_code || "", saved.es_phone_number || "");
    let hintShown = false; // 仅在第一次聚焦时提示一次

    function showPhoneHintOnce() {
      if (hintShown) return;
      hintShown = true;

      const $box = $form.find(".phone-box");
      if ($box.next(".phone-hint").length === 0) {
        // ✅ 若本机没有已保存号码 → 首次登记提示；否则 → 变更提示
        const text = !savedPhone
          ? "提示：这是你首次登记，手机号将作为以后签到的身份识别，请确认电话号码。"
          : "提示：除非需要为他人签到，否则请勿更换电话号码（用于唯一身份识别）";
        $("<div/>", {
          class: "phone-hint",
          text,
          css: { color: "#f99522ff", "font-size": "16px", "margin-top": "4px", "margin-bottom": "6px" }
        }).insertAfter($box);
      }
    }

    function clearPhoneHint() {
      const $h = $form.find('.phone-hint');
      if ($h.length) $h.text(''); // 仅清空内容；也可用 $h.hide() 隐藏
    }
    // 聚焦任一电话字段时，若本地有号码则显示一次提示（不打断操作）
    $cc.on("focus", showPhoneHintOnce);
    $num.on("focus", showPhoneHintOnce);

    // 合并后的唯一提交处理器（确保取消时不提交）
    $form.off("submit.ap").on("submit.ap", function (e) {
      e.preventDefault();

      const $submit = $form.find('input[type=submit], button[type=submit]');

      // 若上次请求尚未完成，直接忽略（防抖）
      if ($submit.prop('disabled')) return;

      const curPhone = normalizePhone($cc.val(), $num.val());

      // —— 首次登记：无 savedPhone → 弹一次确认；Cancel 就返回，且不禁用按钮
      if (!savedPhone) {
        const okFirst = window.confirm(
          "首次登记：系统将以此手机号作为你以后签到的身份识别。\n请确认号码无误后提交。\n确定提交吗？"
        );
        if (!okFirst) {
          // 允许返回修改
          clearPhoneHint();
          setTimeout(() => { $num.focus(); $num.select && $num.select(); }, 0);
          return;
        }
      }
      // —— 非首次：若号码被更改 → 弹确认；Cancel 就返回，且不禁用按钮
      else if (curPhone && curPhone !== savedPhone) {
        const okChange = window.confirm(
          "检测到你更改了电话号码。\n如果不是在录入他人信息，请不要更改号码。\n确定要用新号码提交吗？"
        );
        if (!okChange) {
          clearPhoneHint();
          setTimeout(() => { $num.focus(); $num.select && $num.select(); }, 0);
          return;
        }
      }

      // ✅ 只有通过了上面的确认，才开始“防重复提交”
      $submit.prop('disabled', true).attr('aria-busy', 'true');

      const formData = {
        es_first_name: $form.find("input[name=es_first_name]").val(),
        es_last_name: $form.find("input[name=es_last_name]").val(),
        es_email: $form.find("input[name=es_email]").val(),
        es_phone_country_code: $cc.val(),
        es_phone_number: $num.val(),
        es_fellowship: $form.find("select[name=es_fellowship]").val(),
      };

      storage.set("es_attendance_form_data", formData);

      api.post("es_handle_attendance", formData)
        .done((resp) => {
          $(".es-message").remove();
          const ok = !!(resp && resp.success);
          const msg = (resp && resp.data && resp.data.message) || (ok ? "Success" : "Error");
          displayMessage($form, msg, ok ? "green" : "red");
          alert(msg);
        })
        .always(() => {
          // 无论成功失败，都恢复按钮
          $submit.prop('disabled', false).removeAttr('aria-busy');
        });
    });

    $form.on("focus", "input,select", function () { $(".es-message").remove(); });

    $("#last_date_filter").datepicker({ dateFormat: "dd/mm/yy", changeYear: true, changeMonth: true, showButtonPanel: true, yearRange: "c-100:c+0" });
  }

  /* ========== 后台列表 ========== */
  function getFilters() {
    return {
      is_member: $("#es_member_filter").val(),
      fellowship: $("#es_fellowship_filter").val(),
      start_date_filter: $("#start_date_filter").val(),
      end_date_filter: $("#end_date_filter").val(),
      last_name: $("#last_name_filter").val(),
      first_name: $("#first_name_filter").val(),
      email: $("#email_filter").val(),
      phone: $("#phone_filter").val(),
      is_new: $("#is_new_filter").is(":checked") ? 'true' : '',
      percentage_filter: $("#percentage_filter").is(":checked"),
    };
  }

  function bindToggleRowEvent() {
    $("tbody").off("click", ".toggle-row").on("click", ".toggle-row", function () {
      $(this).closest("tr").toggleClass("is-expanded");
    });
  }

  function bindPaginationEvent() {
    $(document).off("click", ".pagination-links a").on("click", ".pagination-links a", function (e) {
      e.preventDefault();
      const href = $(this).attr("href") || "";
      const match = href.match(/paged=(\d+)/);
      const page = match ? parseInt(match[1], 10) : 1;
      fetchFilteredResults(page);
    });
  }

  function renderTableHTML(html) {
    $(S.tableBox).html(html);
    bindToggleRowEvent();
    bindPaginationEvent();
  }

  function fetchFilteredResults(page = 1) {
    const params = { ...getFilters(), paged: page };
    showLoader(true);
    api.post("es_filter_attendance", params).done((resp) => {
      if (resp && resp.success) {
        renderTableHTML(resp.data.table_html);
        storage.set("filter_params", params);
      } else {
        alert("加载失败，请稍后再试。");
      }
    }).always(() => showLoader(false));
  }

  function exportCSV() {
    const params = getFilters();
    api.postRaw("es_export_attendance_csv", params).done((response) => {
      const blob = new Blob([response], { type: "text/csv" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      const d = new Date(); const dd = String(d.getDate()).padStart(2, "0"); const mm = String(d.getMonth() + 1).padStart(2, "0"); const yy = d.getFullYear();
      a.href = url; a.download = `Attendance_Report_${dd}-${mm}-${yy}.csv`;
      document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    });
  }

  function bulkMemberUpdate(action) {
    if (!/make_member|make_non_member/.test(action)) return;
    const ids = $('input[name="bulk-select[]"]:checked').map(function () { return this.value; }).get();
    if (!ids.length) return;
    showLoader(true);
    api.post("handle_member_status_update", { ids, member_action: action })
      .done((resp) => {
        const msg = (resp && resp.data && resp.data.message) || "Member status updated.";
        alert(msg);
        const params = storage.get("filter_params") || getFilters();
        fetchFilteredResults(params.paged || 1);
      })
      .fail(() => { alert('更新失败，请稍后再试。'); })
      .always(() => { showLoader(false); });
  }

  function initAdmin() {
    if (!$(S.tableBox).length) return;
    $(document).on("click", "#filter-button", function (e) { e.preventDefault(); fetchFilteredResults(1); });
    $(document).on("click", "#export-csv-button", function (e) { e.preventDefault(); exportCSV(); });
    $(document).on("click", "#doaction, #doaction2", function (e) { e.preventDefault(); const action = $(this).prev("select").val(); bulkMemberUpdate(action); });
    bindToggleRowEvent();
    bindPaginationEvent();
  }

  /* ========== 详情弹窗 ========== */

  function initModal() {
    const $modal = $(S.modal);
    if (!$modal.length) return;

    // 简单 spinner
    const spinnerHTML = '<div class="ap-modal-loading"><div class="ap-spinner"></div></div>';

    // 计算日期：在前台用 fe_*，后台用 admin 的；缺省用今天
    function ymd(d) {
      const x = (d instanceof Date) ? d : new Date(d);
      const yyyy = x.getFullYear();
      const mm = String(x.getMonth() + 1).padStart(2, "0");
      const dd = String(x.getDate()).padStart(2, "0");
      return `${yyyy}-${mm}-${dd}`;
    }
    function getDateRangeForModal() {
      const $fe = $(".ap-frontend-dashboard");
      if ($fe.length) {
        const s = $fe.find("#fe_start_date_filter").val();
        const e = $fe.find("#fe_end_date_filter").val();
        return { start: s || ymd(new Date()), end: e || ymd(new Date()) };
      }
      const s = $("#start_date_filter").val();
      const e = $("#end_date_filter").val();
      return { start: s || ymd(new Date()), end: e || ymd(new Date()) };
    }

    // 跟踪当前请求，避免竞态
    let currentXhr = null;
    let lastReqId = 0;

    $(document).on("click", ".view-attendance-button", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const id = $(this).data("attendance-id");
      const dr = getDateRangeForModal();

      // 中止上一个仍在进行的请求
      if (currentXhr && currentXhr.readyState !== 4) {
        try { currentXhr.abort(); } catch (_) { }
      }

      // 打开弹窗并显示 loading
      $(S.modalContent).html(spinnerHTML);
      $modal.show();

      // 记录本次请求 id
      const reqId = ++lastReqId;

      // 发请求
      currentXhr = $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        data: {
          action: "get_attendance_info",
          nonce: esAjax.nonce,
          attendance_id: id,
          start_date_filter: dr.start,
          end_date_filter: dr.end
        }
      })
        .done(function (html) {
          // 只渲染“最后一次点击”的结果
          if (reqId === lastReqId) {
            $(S.modalContent).html(html);
          }
        })
        .fail(function (xhr, status) {
          if (status === "abort") return; // 被后续点击打断，忽略
          $(S.modalContent).html('<div class="ap-modal-error">加载失败，请稍后再试。</div>');
        });
    });

    $(document).on("click", "#attendance-info-modal .close", function () {
      // 关闭时也中止当前请求，避免回来后把内容写进来
      if (currentXhr && currentXhr.readyState !== 4) {
        try { currentXhr.abort(); } catch (_) { }
      }
      $modal.hide();
    });
  }



  (function boot() { initForm(); initAdmin(); initModal(); })();

  /* ========== 前台 dashboard（短代码） ========== */
  (function ($) {
    // 全选/反选（用事件委托以适配 AJAX 重渲染）
    $(document).on('change', '.ap-frontend-dashboard #fe-check-all', function () {
      const $wrap = $(this).closest('.ap-frontend-dashboard');
      $wrap.find('input.fe-check-item').prop('checked', this.checked);
    });
    $(document).on('change', '.ap-frontend-dashboard input.fe-check-item', function () {
      const $wrap = $(this).closest('.ap-frontend-dashboard');
      const all = $wrap.find('input.fe-check-item').length;
      const selected = $wrap.find('input.fe-check-item:checked').length;
      $wrap.find('#fe-check-all').prop('checked', all > 0 && selected === all);
    });

    function feContainer() {
      const $c = $(".ap-frontend-dashboard");
      return $c.length ? $c : null;
    }
    function feGetFilters($c) {
      return {
        fellowship: $c.find("#fe_fellowship_filter").val(),
        is_member: $c.find("#fe_member_filter").val(),
        last_name: $c.find("#fe_last_name_filter").val(),
        first_name: $c.find("#fe_first_name_filter").val(),
        phone: $c.find("#fe_phone_filter").val(),
        email: $c.find("#fe_email_filter").val(),
        start_date_filter: $c.find("#fe_start_date_filter").val(),
        end_date_filter: $c.find("#fe_end_date_filter").val(),
        is_new: $c.find("#fe_is_new_filter").is(":checked") ? 'true' : '',
        percentage_filter: $c.find("#fe_percentage_filter").is(":checked"),
      };
    }
    function feCurrentPage($c) {
      const $nav = $c.find(".ap-pager");
      const p = parseInt($nav.attr("data-page"), 10);
      return Number.isFinite(p) && p > 0 ? p : 1;
    }
    function feShowLoader($c, show) {
      const $box = $c.find("#loader-box");
      if (!$box.length) return;
      show ? $box.show() : $box.hide();
    }

    // 统一由 feRenderTable 开关 loader；只替换 #table-wrap
    function feRenderTable($c, params) {
      feShowLoader($c, true);
      return $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        dataType: "json",
        data: { action: "es_filter_attendance", nonce: esAjax.nonce, fe: 1, ...params },
      })
        .done(function (resp) {
          if (resp && resp.success) {
            $c.find("#table-wrap").html(resp.data.table_html);
            $c.find('#fe-check-all').prop('checked', false);
            $c.off("click.fePage", ".ap-pager a").on("click.fePage", ".ap-pager a", function (e) {
              e.preventDefault();
              const $a = $(this);
              if ($a.attr("aria-disabled") === "true" || $a.hasClass("disabled")) return;
              const nextPage = parseInt($a.data("page"), 10) || 1;
              const paramsNext = { ...feGetFilters($c), paged: nextPage };
              feRenderTable($c, paramsNext);
            });
            requestAnimationFrame(function () { feScrollToContainerTop($c, 10); });
          } else {
            alert("加载失败，请稍后再试。");
          }
        })
        .fail(function () { alert("加载失败，请稍后再试。"); })
        .always(function () { feShowLoader($c, false); });
    }

    function feExportCSV($c) {
      const s = $c.find("#fe_start_date_filter").val();
      const e = $c.find("#fe_end_date_filter").val();

      // 日期格式：1/7/2024（不补零），空就用今天
      function dmyLoose(iso) {
        const d = iso ? new Date(iso) : new Date();
        const day = d.getDate();
        const mon = d.getMonth() + 1;
        const yr = d.getFullYear();
        return `${day}/${mon}/${yr}`;
      }
      const titleLine = `Date Range,${dmyLoose(s)} to ${dmyLoose(e)}`;

      $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        data: { action: "es_export_attendance_csv", nonce: esAjax.nonce, ...feGetFilters($c) }
      }).done(function (csv) {
        // 去掉服务端可能加的 BOM，避免出现在中间
        if (csv && csv.charCodeAt && csv.charCodeAt(0) === 0xFEFF) {
          csv = csv.slice(1);
        }
        // 在最前面加标题行；再加 BOM 让 Excel 识别 UTF-8
        const finalCsv = "\uFEFF" + titleLine + "\n" + csv;

        const blob = new Blob([finalCsv], { type: "text/csv" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        const d = new Date(), dd = String(d.getDate()).padStart(2, "0"), mm = String(d.getMonth() + 1).padStart(2, "0"), yy = d.getFullYear();
        a.href = url;
        a.download = `Attendance_Report_${dd}-${mm}-${yy}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
      });
    }


    // 批量：按钮 Loading 文案直到表格刷新完成
    function feBulkAction($c) {
      const action = $c.find("#fe-bulk-action-selector").val();
      if (!/make_member|make_non_member/.test(action)) return;
      const ids = $c.find('input[name="bulk-select[]"]:checked').map(function () { return this.value; }).get();
      if (!ids.length) return;

      const $btn = $c.find("#fe-doaction");
      const oldText = $btn.text();
      $btn.prop("disabled", true).attr("aria-busy", "true").text("Loading…");
      feShowLoader($c, true);

      $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        dataType: "json",
        data: { action: "handle_member_status_update", nonce: esAjax.nonce, ids, member_action: action },
      })
        .done(function (resp) {
          alert((resp && resp.data && resp.data.message) || "Member status updated.");
          const params = { ...feGetFilters($c), paged: feCurrentPage($c) };
          feRenderTable($c, params).always(function () {
            $btn.prop("disabled", false).removeAttr("aria-busy").text(oldText);
            feShowLoader($c, false);
          });
        })
        .fail(function () {
          feShowLoader($c, false);
          $btn.prop("disabled", false).removeAttr("aria-busy").text(oldText);
          alert("更新失败，请稍后再试。");
        });
    }

    $(function () {
      const $c = feContainer();
      if (!$c) return;

      $c.on("click", "#fe-filter-button", function (e) {
        e.preventDefault();
        const params = { ...feGetFilters($c), paged: 1 };
        feRenderTable($c, params);
      });

      $c.on("click", "#fe-doaction", function (e) {
        e.preventDefault();
        feBulkAction($c);
      });

      $c.on("click", "#fe-export-csv-button", function (e) {
        e.preventDefault();
        feExportCSV($c);
      });

      $c.off("click.fePage", ".ap-pager a").on("click.fePage", ".ap-pager a", function (e) {
        e.preventDefault();
        const $a = $(this);
        if ($a.attr("aria-disabled") === "true" || $a.hasClass("disabled")) return;
        const nextPage = parseInt($a.data("page"), 10) || 1;
        const paramsNext = { ...feGetFilters($c), paged: nextPage };
        feRenderTable($c, paramsNext);
      });
      // 首屏/刷新：不自动 AJAX，使用服务器渲染的第 1 页
    });


  })(jQuery);

  // ========== First Timers（无刷新刷新 + 导出） ==========
  (function ($) {
    function apAjaxUrl() {
      const base = esAjax.ajaxurl || '';
      return base + (base.indexOf('?') >= 0 ? '&' : '?') + '_r=' + Date.now();
    }

    function $box() { const $c = $("#ap-first-timers"); return $c.length ? $c : null; }
    function getRange($c) {
      const s = $c.find("#ap-ft-start").val();
      const e = $c.find("#ap-ft-end").val();
      return { start: s || new Date().toISOString().slice(0, 10), end: e || new Date().toISOString().slice(0, 10) };
    }
    function showLoader($c, show) {
      const $l = $c.find("#ap-ft-loader");
      if (!$l.length) return;
      show ? $l.show() : $l.hide();
    }
    function refreshList($c) {
      const rng = getRange($c);
      showLoader($c, true);
      return $.ajax({
        url: apAjaxUrl(),
        type: "POST",
        dataType: "json",
        data: { action: "ap_first_timers_query", nonce: esAjax.nonce, ...rng }
      }).done(function (resp) {
        if (resp && resp.success) {
          $c.find("#ap-ft-list").html(resp.data.html);
          $c.attr('data-count', (resp.data && resp.data.count) ? resp.data.count : 0);
          const t = resp.data.generated_at || '';
          $c.find("#ap-ft-note").text((t ? `数据生成于 ${t}（本站时区）。` : ''));
        } else {
          alert("加载失败，请稍后再试。");
        }
      }).fail(function (xhr) {
        console.error('AJAX fail', xhr && xhr.status, xhr && xhr.responseText);
        alert("加载失败，请稍后再试。");
      }).always(function () {
        showLoader($c, false);
      });
    }
    function exportExcel($c) {
      const cnt = parseInt($c.attr('data-count') || '0', 10);
      if (!Number.isFinite(cnt) || cnt <= 0) {
        alert('所选日期范围内暂无数据，无法导出。');
        return;
      }
      const rng = getRange($c);
      // 用表单方式下载（避免 fetch/$.ajax 处理 blob 的兼容问题）
      const form = document.createElement("form");
      form.method = "POST";
      form.action = apAjaxUrl();
      form.style.display = "none";

      const add = (k, v) => { const i = document.createElement("input"); i.type = "hidden"; i.name = k; i.value = v; form.appendChild(i); };
      add("action", "ap_first_timers_export");
      add("nonce", esAjax.nonce);
      add("start", rng.start);
      add("end", rng.end);
      add("_r", Date.now().toString());

      document.body.appendChild(form);
      form.submit();
      setTimeout(() => form.remove(), 1000);
    }

    $(function () {
      const $c = $box();
      if (!$c) return;

      // 绑定事件
      $c.on("click", "#ap-ft-refresh", function (e) { e.preventDefault(); refreshList($c); });
      $c.on("click", "#ap-ft-export", function (e) { e.preventDefault(); exportExcel($c); });

      // 可选：切日期即刷新
      $c.on("change", "#ap-ft-start, #ap-ft-end", function () { refreshList($c); });
    });
  })(jQuery);

});
