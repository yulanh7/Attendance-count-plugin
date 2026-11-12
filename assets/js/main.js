/* =========================================================
 * Attendance Plugin - main.js (完整合并版 + 新来宾功能)
 * =======================================================*/
jQuery(function ($) {
  const AP = (window.AP = window.AP || {});
  const S = {
    // 原有选择器
    tableBox: "#filter-table-response",
    loaderBox: "#loader-box",
    form: "#es_attendance_form",
    modal: "#attendance-info-modal",
    modalContent: "#attendance-info-modal-content",
    // 新增三合一表单选择器
    container: ".es-attendance-container",
    quickForm: "#es_quick_attendance_form",
    firstTimeForm: "#es_first_time_form",
    profileForm: "#es_profile_form",
  };

  // 界面文字（由 PHP 按堂注入 window.apI18n）；缺 key 时返回 key 本身，方便发现遗漏
  const I18N = window.apI18n || {};
  const CTX = window.apCtx || { congregation: "mandarin", isAdmin: false, fellowships: {}, labels: {} };
  function T(key, args) {
    let s = Object.prototype.hasOwnProperty.call(I18N, key) ? String(I18N[key]) : key;
    if (args) Object.keys(args).forEach((k) => { s = s.split("{" + k + "}").join(String(args[k])); });
    return s;
  }
  function cLabel(c) { return (CTX.labels && CTX.labels[c]) || c || ""; }
  AP.T = T;

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

  function normalizePhone(cc, num) {
    let n = (num || "").replace(/[\s\-()]/g, "");
    if (cc === "+61" && n.charAt(0) === "0") n = n.slice(1);
    return (cc || "") + n;
  }

  function getSubmitButton($form) {
    const $active = $(document.activeElement);
    if ($active.is('button[type=submit], input[type=submit]')) return $active;
    return $form.find('button[type=submit], input[type=submit]').first();
  }

  function setSubmitting($btn, on) {
    if (!$btn || !$btn.length) return;

    // 根据所在容器决定提示栏插入位置
    function ensureHintEl() {
      const $row = $btn.closest('.profile-action-row');
      if ($row.length) {
        // 提示放在整个按钮行的后面 => 强制下一行显示
        let $h = $row.next('.submit-hint');
        if (!$h.length) {
          $h = $('<div class="submit-hint" role="status" aria-live="polite"/>').insertAfter($row);
        }
        return $h;
      } else {
        let $h = $btn.next('.submit-hint');
        if (!$h.length) {
          $h = $('<div class="submit-hint" role="status" aria-live="polite"/>').insertAfter($btn);
        }
        return $h;
      }
    }

    const $hint = ensureHintEl();

    // 记录初始文案
    if ($btn.data('init-label') === undefined) {
      const initLabel = $btn.is('input') ? $btn.val() : $btn.html();
      $btn.data('init-label', initLabel);
    }

    if (on) {
      if ($btn.data('submitting') === true) return;
      $btn.data('submitting', true);

      if ($btn.data('orig-label') === undefined) {
        let original = $btn.is('input') ? $btn.val() : $btn.html();
        if (original === T('js_submitting')) original = $btn.data('init-label');
        $btn.data('orig-label', original);
      }

      if ($btn.is('input')) $btn.val(T('js_submitting')); else $btn.text(T('js_submitting'));
      $btn.prop('disabled', true).attr('aria-busy', 'true');
      $hint.text(T('js_processing')).show();

    } else {
      if ($btn.data('submitting') !== true) return;
      $btn.data('submitting', false);

      const original = $btn.data('orig-label');
      const fallback = $btn.data('init-label');
      const textToRestore = (original !== undefined) ? original : fallback;

      if (textToRestore !== undefined) {
        if ($btn.is('input')) $btn.val(textToRestore); else $btn.html(textToRestore);
      }

      $btn.removeData('orig-label');
      $btn.prop('disabled', false).removeAttr('aria-busy');
      $hint.text('').hide();
    }
  }

  function ensureProfileActionButtons($form) {
    // 找到 submit
    const $submit = $form.find('button[type=submit], input[type=submit]').last();
    if (!$submit.length) return;

    // 创建/查找按钮行
    let $row = $form.find('.profile-action-row');
    if (!$row.length) {
      $row = $('<div class="profile-action-row"></div>');
      $submit.after($row);
      $row.append($submit); // 把 submit 放进行内
    } else {
      if (!$row.find($submit).length) $row.prepend($submit);
    }

    // 取消
    let $cancel = $row.find('#profile-cancel-edit');
    if (!$cancel.length) {
      $cancel = $('<button type="button" id="profile-cancel-edit" class="switch-btn"></button>').text(T('btn_cancel'));
      $row.append($cancel);
    }

    // 修改其他号码
    let $editOther = $row.find('#profile-edit-other');
    if (!$editOther.length) {
      $editOther = $('<button type="button" id="profile-edit-other" class="switch-btn" style="display:none;"></button>').text(T('btn_edit_other_number'));
      $row.append($editOther);
    }
  }

  // 签到成功或「今天已签过」都说明号码是本人的 → 记住这个号码
  function shouldAdoptCurrentPhone(resp) {
    if (resp && resp.success) return true;
    const code = resp && resp.data && resp.data.code;
    return code === 'already_signed' || code === 'signed_elsewhere_today';
  }

  /* ========== 原有前台表单（保持不变） ========== */
  function initForm() {
    const $form = $(S.form);
    if (!$form.length) return;

    const saved = storage.get("es_attendance_form_data", {});
    $form.find("input[name=es_first_name]").val(saved.es_first_name || "");
    $form.find("input[name=es_last_name]").val(saved.es_last_name || "");
    $form.find("input[name=es_email]").val(saved.es_email || "");
    $form.find("select[name=es_phone_country_code]").val(saved.es_phone_country_code || "+61");
    $form.find("input[name=es_phone_number]").val(saved.es_phone_number || "");
    $form.find("[name=es_fellowship]").val(saved.es_fellowship || "");
    $form.find("select[name=es_referral_source]").val(saved.es_referral_source || "");

    // 原有手机号一致性与提示逻辑
    const $cc = $form.find("select[name=es_phone_country_code]");
    const $num = $form.find("input[name=es_phone_number]");

    let savedPhone = normalizePhone(saved.es_phone_country_code || "", saved.es_phone_number || "");
    let hintShown = false;

    function showPhoneHintOnce() {
      if (hintShown) return;
      if (!savedPhone) return
      hintShown = true;

      const $box = $form.find(".phone-box");
      if ($box.next(".phone-hint").length === 0) {
        const text = T("js_phone_change_hint");
        $("<div/>", {
          class: "phone-hint",
          text,
          css: { color: "#f99522ff", "font-size": "16px", "margin-top": "4px", "margin-bottom": "6px" }
        }).insertAfter($box);
      }
    }

    function clearPhoneHint() {
      const $h = $form.find('.phone-hint');
      if ($h.length) $h.text('');
    }

    $cc.on("focus", showPhoneHintOnce);
    $num.on("focus", showPhoneHintOnce);

    $form.off("submit").off("submit.ap").on("submit.ap", function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      e.stopPropagation();
      const $submit = getSubmitButton($form);
      if ($submit && $submit.prop('disabled')) return;

      const curPhone = normalizePhone($cc.val(), $num.val());

      function doSubmit() {
        setSubmitting($submit, true);
        const formData = {
          congregation: CTX.congregation,
          es_first_name: $form.find("input[name=es_first_name]").val(),
          es_last_name: $form.find("input[name=es_last_name]").val(),
          es_email: $form.find("input[name=es_email]").val(),
          es_phone_country_code: $cc.val(),
          es_phone_number: $num.val(),
          es_fellowship: $form.find("[name=es_fellowship]").val(),
          es_referral_source: $form.find("select[name=es_referral_source]").val(),
        };
        storage.set("es_attendance_form_data", formData);
        api.post("es_handle_attendance", formData)
          .done((resp) => {
            $(".es-message").remove();
            const ok = !!(resp && resp.success);
            const msg = (resp && resp.data && (resp.data.msg || resp.data.message)) || (ok ? "Success" : "Error");
            displayMessage($form, msg, ok ? "green" : "red");
            alert(msg);
            if (shouldAdoptCurrentPhone(resp)) {
              savedPhone = normalizePhone($cc.val(), $num.val());
              const formData = {
                es_first_name: $form.find("input[name=es_first_name]").val(),
                es_last_name: $form.find("input[name=es_last_name]").val(),
                es_email: $form.find("input[name=es_email]").val(),
                es_phone_country_code: $cc.val(),
                es_phone_number: $num.val(),
                es_fellowship: $form.find("[name=es_fellowship]").val(),
                es_referral_source: $form.find("select[name=es_referral_source]").val(),
              };
              Object.assign(saved, formData);
              storage.set("es_attendance_form_data", formData);

            }
          })
          .always(() => {
            hintShown = false;
            clearPhoneHint();
            setSubmitting($submit, false);
          });
      }

      // 情况 A：本机没有保存过号码
      if (!savedPhone) {
        api.post("ap_check_phone_exists", {
          cc: $cc.val(),
          num: $num.val()
        }).done(function (resp) {
          const exists = !!(resp && resp.success && resp.data && resp.data.exists);
          if (!exists) {
            const ok = window.confirm(
              T("js_confirm_new_phone")
            );
            if (!ok) { setTimeout(() => { $num.focus(); $num.select && $num.select(); }, 0); return; }
          }
          doSubmit();
        }).fail(function () {
          const ok = window.confirm(
            T("js_confirm_unknown_phone")
          );
          if (!ok) { setTimeout(() => { $num.focus(); $num.select && $num.select(); }, 0); return; }
          doSubmit();
        });
        return;
      }

      // 情况 B：本机有保存号码
      if (curPhone && curPhone !== savedPhone) {
        const ok = window.confirm(
          T("js_confirm_phone_changed")
        );
        if (!ok) { setTimeout(() => { $num.focus(); $num.select && $num.select(); }, 0); return; }
      }

      doSubmit();
    });

    $form.on("focus", "input,select", function () { $(".es-message").remove(); });

    $("#last_date_filter").datepicker({ dateFormat: "dd/mm/yy", changeYear: true, changeMonth: true, showButtonPanel: true, yearRange: "c-100:c+0" });
  }

  /* ========== 新增：三合一表单逻辑 ========== */
  function switchToForm(targetForm) {
    $(S.quickForm + ", " + S.firstTimeForm + ", " + S.profileForm).hide();
    $(targetForm).show();
    $(".es-message").remove();
  }

  function loadSavedData($form) {
    const saved = storage.get("es_attendance_form_data", {});
    $form.find("input[name=es_first_name]").val(saved.es_first_name || "");
    $form.find("input[name=es_last_name]").val(saved.es_last_name || "");
    $form.find("input[name=es_email]").val(saved.es_email || "");
    $form.find("select[name=es_phone_country_code]").val(saved.es_phone_country_code || "+61");
    $form.find("input[name=es_phone_number]").val(saved.es_phone_number || "");
    $form.find("[name=es_fellowship]").val(saved.es_fellowship || "");
    $form.find("select[name=es_referral_source]").val(saved.es_referral_source || "");
  }

  function getFormData($form) {
    const data = {
      congregation: CTX.congregation,
      es_first_name: $form.find("input[name=es_first_name]").val() || "",
      es_last_name: $form.find("input[name=es_last_name]").val() || "",
      es_email: $form.find("input[name=es_email]").val() || "",
      es_phone_country_code: $form.find("select[name=es_phone_country_code]").val() || "+61",
      es_phone_number: $form.find("input[name=es_phone_number]").val() || "",
      es_fellowship: $form.find("[name=es_fellowship]").val() || "",
      es_referral_source: $form.find("select[name=es_referral_source]").val() || "",
    };
    const $home = $form.find("select[name=es_home_congregation]");
    if ($home.length) data.es_home_congregation = $home.val() || CTX.congregation;
    return data;
  }

  /* ---------- 所属堂 / 团契下拉 ---------- */
  function fellowshipOwner(key) {
    if (!key || key === "other") return null;
    const all = CTX.fellowships || {};
    for (const c of Object.keys(all)) {
      if (Object.prototype.hasOwnProperty.call(all[c], key)) return c;
    }
    return null;
  }

  function fellowshipText(key) {
    const owner = fellowshipOwner(key);
    if (!owner) return key;
    const name = CTX.fellowships[owner][key];
    return CTX.congregation === "english" ? `${name} (${cLabel(owner)})` : `${name}（${cLabel(owner)}）`;
  }

  // 按「所属堂」重建团契下拉：
  // 所属堂没改、而原来的团契属于别的堂时，保留原团契作为一个额外选项（显示「团契名（堂名）」）
  function rebuildProfileFellowships($form, keepOriginal) {
    const $sel = $form.find("select[name=es_fellowship]");
    if (!$sel.length) return;
    const home = $form.find("select[name=es_home_congregation]").val() || CTX.congregation;
    const origHome = $form.data("orig-home");
    const origF = $form.data("orig-fellowship") || "";
    const list = (CTX.fellowships && CTX.fellowships[home]) || {};

    $sel.empty();
    $("<option>", { value: "", disabled: true, text: T("fellowship_placeholder") }).appendTo($sel);
    Object.keys(list).forEach((k) => $("<option>", { value: k, text: list[k] }).appendTo($sel));

    const sameHome = home === origHome;
    if (sameHome && origF && !Object.prototype.hasOwnProperty.call(list, origF) && fellowshipOwner(origF)) {
      $("<option>", { value: origF, text: fellowshipText(origF) }).appendTo($sel);
    }
    $sel.val(keepOriginal && sameHome && origF ? origF : "");
  }

  function resetProfileForm() {
    const $form = $(S.profileForm);
    $form.find("#profile-user-info").hide();
    $form.find("#profile-check-section").show();
    $form.find("input[name=es_first_name]").val("");
    $form.find("input[name=es_last_name]").val("");
    $form.find("input[name=es_email]").val("").attr("placeholder", T("email_placeholder"));
    $form.removeData("orig-home").removeData("orig-fellowship");
    $form.find("select[name=es_home_congregation]").val(CTX.congregation);
    rebuildProfileFellowships($form, false);
    $form.find("select[name=es_referral_source]").val("");
    $form.find("#profile-cancel-edit").hide();
    $form.find("#profile-edit-other").hide();
    $(".es-message").remove();
  }

  function prefillPhoneFromStorage($form) {
    const saved = storage.get("es_attendance_form_data", {}) || {};
    const cc = saved.es_phone_country_code || "+61";
    const num = saved.es_phone_number || "";
    $form.find("select[name=es_phone_country_code]").val(cc);
    $form.find("input[name=es_phone_number]").val(num);
  }

  function syncQuickFormPhone(cc, num) {
    const saved = storage.get("es_attendance_form_data", {}) || {};
    const updated = { ...saved, es_phone_country_code: cc || "+61", es_phone_number: num || "" };
    storage.set("es_attendance_form_data", updated);
    const $quick = $(S.quickForm);
    if ($quick.length) {
      $quick.find("select[name=es_phone_country_code]").val(updated.es_phone_country_code || "+61");
      $quick.find("input[name=es_phone_number]").val(updated.es_phone_number || "");
    }
  }

  function resetFirstTimeForm() {
    const $form = $(S.firstTimeForm);
    $form.find("input[name=es_first_name]").val("");
    $form.find("input[name=es_last_name]").val("");
    $form.find("input[name=es_email]").val("");
    $form.find("select[name=es_phone_country_code]").val("+61"); // 如需默认国家码
    $form.find("input[name=es_phone_number]").val("");
    $form.find("[name=es_fellowship]").val("other");
    $form.find("select[name=es_referral_source]").val("");
    $(".es-message").remove();
  }

  function checkPhoneAndLoadProfile(phone, $form) {
    const $checkBtn = $("#check-profile-phone");
    const originalText = $checkBtn.text();

    $checkBtn.prop("disabled", true).text(T("js_searching"));

    api.post("ap_check_phone_exists", {
      cc: $form.find("select[name=es_phone_country_code]").val(),
      num: $form.find("input[name=es_phone_number]").val()
    })
      .done((resp) => {
        const exists = !!(resp && resp.success && resp.data && resp.data.exists);

        if (!exists) {
          const go = window.confirm(T("js_confirm_not_registered"));
          $checkBtn.prop("disabled", false).text(originalText);
          if (go) {
            resetFirstTimeForm();
            switchToForm(S.firstTimeForm);
          } else {
            setTimeout(() => {
              const $numInput = $form.find("input[name=es_phone_number]");
              $numInput.focus();
              $numInput.select && $numInput.select();
            }, 0);
          }
          return;
        }

        // 号码存在：等资料加载完再恢复按钮
        loadUserProfileForEdit(phone, $form)
          .always(() => {
            $checkBtn.prop("disabled", false).text(originalText);
          });
      })
      .fail(() => {
        displayMessage($form, T("js_lookup_failed"), "red");
        $checkBtn.prop("disabled", false).text(originalText);
      });
  }

  function loadUserProfileForEdit(phone, $form) {
    const $info = $form.find("#profile-user-info");
    const $check = $form.find("#profile-check-section");

    // 返回 jqXHR 让上层等待完成后再恢复按钮
    return api.post("ap_get_user_profile", { phone: phone, congregation: CTX.congregation })
      .done((resp) => {
        if (resp && resp.success && resp.data) {
          const data = resp.data;

          $form.find("input[name=es_first_name]").val(data.first_name || "");
          $form.find("input[name=es_last_name]").val(data.last_name || "");
          // 邮箱只显示打码版本作为提示；留空提交 = 不修改原邮箱
          $form.find("input[name=es_email]")
            .val("")
            .attr("placeholder", data.email_masked
              ? T("js_email_masked_placeholder", { email: data.email_masked })
              : T("email_placeholder"));

          const home = data.home_congregation || CTX.congregation;
          $form.data("orig-home", home).data("orig-fellowship", data.fellowship || "");
          $form.find("select[name=es_home_congregation]").val(home);
          rebuildProfileFellowships($form, true);

          $check.hide();
          $info.show();
          ensureProfileActionButtons($form);

          // 查找完成时：只显示"取消"
          $form.find("#profile-cancel-edit").show();
          $form.find("#profile-edit-other").hide();

        } else {
          displayMessage($form, T("js_load_profile_failed"), "red");
        }
      })
      .fail(() => {
        displayMessage($form, T("js_load_profile_failed_retry"), "red");
      });
  }

  /* ---------- 签到结果：同日换堂 / 转堂提示 ---------- */
  function showPrompt($form, text, buttons) {
    $(".es-prompt").remove();
    const $box = $("<div>", { class: "es-message es-prompt", css: { background: "#fff7e6", color: "#333", border: "1px solid #f99522", padding: "10px", "margin-top": "10px", "border-radius": "5px" } });
    $("<div>", { class: "es-prompt-text", text: text, css: { "margin-bottom": "8px", "white-space": "pre-line" } }).appendTo($box);
    const $row = $("<div>", { class: "es-prompt-actions", css: { display: "flex", gap: "8px", "flex-wrap": "wrap" } }).appendTo($box);
    buttons.forEach((b) => {
      $("<button>", { type: "button", class: "switch-btn " + (b.cls || ""), text: b.label })
        .on("click", function () { $(this).closest(".es-prompt").find("button").prop("disabled", true); b.onClick($box); })
        .appendTo($row);
    });
    $box.insertAfter($form);
    return $box;
  }

  // 统一处理签到类接口的返回
  function handleCheckInResult(resp, $form, ctx) {
    const ok = !!(resp && resp.success);
    const d = (resp && resp.data) || {};

    if (!ok && d.code === "signed_elsewhere_today") {
      $(".es-message").remove();
      showMovePrompt($form, d, ctx);
      return;
    }

    const msg = d.msg || d.message || T(ok ? ctx.okKey : ctx.failKey);
    displayMessage($form, msg, ok ? "green" : "red");
    alert(msg);
    if (ok && typeof ctx.onSuccess === "function") ctx.onSuccess(d);
    if (ok && d.suggest_switch) showSwitchPrompt($form, d, ctx);
  }

  function showMovePrompt($form, d, ctx) {
    const here = cLabel(CTX.congregation);
    const existing = cLabel(d.existing_congregation);
    showPrompt($form, d.msg || d.message || "", [
      {
        label: d.move_yes_label || T("move_yes", { here }),
        onClick: ($box) => {
          api.post("es_move_attendance", { phone: ctx.phone, congregation: CTX.congregation })
            .done((resp2) => { $box.remove(); handleCheckInResult(resp2, $form, ctx); })
            .fail(() => { $box.remove(); displayMessage($form, T("js_check_in_failed_retry"), "red"); });
        },
      },
      { label: d.move_no_label || T("move_no", { existing }), onClick: ($box) => { $box.remove(); } },
    ]);
  }

  function showSwitchPrompt($form, d, ctx) {
    const here = cLabel(CTX.congregation);
    const home = cLabel(d.home_congregation);
    // 不能用 displayMessage（会把成功提示清掉），直接放在成功提示后面
    const $box = showPrompt($form, T("switch_prompt", { home, here }), [
      { label: T("switch_yes", { here }), onClick: ($b) => { $b.remove(); goToSwitchProfile(ctx); } },
      { label: T("switch_no"), onClick: ($b) => { $b.remove(); } },
    ]);
    const $msg = $form.nextAll(".es-message").not(".es-prompt").first();
    if ($msg.length) $box.insertAfter($msg);
  }

  // 转堂：打开修改资料，所属堂改成本堂，团契必须重新选择
  function goToSwitchProfile(ctx) {
    const $p = $(S.profileForm);
    resetProfileForm();
    $p.find("select[name=es_phone_country_code]").val(ctx.cc || "+61");
    $p.find("input[name=es_phone_number]").val(ctx.num || "");
    switchToForm(S.profileForm);
    loadUserProfileForEdit(ctx.phone, $p).done((resp) => {
      if (!(resp && resp.success)) return;
      $p.find("select[name=es_home_congregation]").val(CTX.congregation);
      rebuildProfileFellowships($p, false);
      $p.find("select[name=es_fellowship]").trigger("focus");
    });
  }

  function checkInCtx($form, phone, extra) {
    return {
      phone,
      cc: $form.find("select[name=es_phone_country_code]").val(),
      num: $form.find("input[name=es_phone_number]").val(),
      okKey: "js_check_in_ok",
      failKey: "js_check_in_failed",
      ...(extra || {}),
    };
  }

  function doQuickAttendance(phone, $form, $submit) {
    setSubmitting($submit, true);

    api.post("es_quick_attendance", { phone: phone, congregation: CTX.congregation })
      .done((resp) => {
        handleCheckInResult(resp, $form, checkInCtx($form, phone));
      })
      .always(() => {
        setSubmitting($submit, false);
      });
  }

  function doQuickAttendanceFromFirstTime(phone, $form, $submit) {
    setSubmitting($submit, true);

    api.post("es_quick_attendance", { phone: phone, congregation: CTX.congregation })
      .done((resp) => {
        const ok = !!(resp && resp.success);
        const d = (resp && resp.data) || {};
        // 首次登记页走快速签到：失败时给出更明确的提示
        if (!ok && d.code === "already_signed") d.msg = T("js_already_signed");
        else if (!ok && d.code === "db_error") d.msg = T("js_check_in_failed_retry");
        handleCheckInResult(resp, $form, checkInCtx($form, phone, {
          okKey: "js_already_registered_ok",
          failKey: "js_check_in_failed_retry",
          onSuccess: () => syncQuickFormPhone(
            $form.find("select[name=es_phone_country_code]").val(),
            $form.find("input[name=es_phone_number]").val()
          ),
        }));
      })
      .always(() => {
        setSubmitting($submit, false);
      });
  }

  function doFullAttendance($form, $submit) {
    setSubmitting($submit, true);

    const formData = getFormData($form);
    // 复选框：教会新来宾（有勾选才发 1）
    if ($form.find("input[name=es_is_newcomer]").is(':checked')) formData.es_is_newcomer = '1';
    const phone = normalizePhone(formData.es_phone_country_code, formData.es_phone_number);

    api.post("es_handle_attendance", formData)
      .done((resp) => {
        handleCheckInResult(resp, $form, checkInCtx($form, phone, {
          okKey: "js_register_ok",
          failKey: "js_register_failed",
        }));
        if (shouldAdoptCurrentPhone(resp)) {
          storage.set("es_attendance_form_data", formData);
          syncQuickFormPhone(formData.es_phone_country_code, formData.es_phone_number);
        }
      })
      .always(() => {
        setSubmitting($submit, false);
      });
  }

  function doUpdateProfile($form, $submit) {
    // 提交期间需要禁用的按钮
    const $alsoDisable = $form.find('#profile-cancel-edit, #profile-edit-other, #profile-to-quick, #profile-to-first');

    setSubmitting($submit, true);
    $alsoDisable.prop('disabled', true).attr('aria-disabled', 'true');

    const formData = getFormData($form);

    api.post("ap_update_profile", formData)
      .done((resp) => {
        $(".es-message").remove();
        const ok = !!(resp && resp.success);
        const d = (resp && resp.data) || {};
        const msg = d.msg || d.message || T(ok ? "js_profile_update_ok" : "js_profile_update_failed");
        displayMessage($form, msg, ok ? "green" : "red");
        alert(msg);

        if (ok) {
          const saved = storage.get("es_attendance_form_data", {});
          storage.set("es_attendance_form_data", {
            ...saved,
            es_first_name: formData.es_first_name,
            es_last_name: formData.es_last_name,
            es_email: formData.es_email,
            es_fellowship: formData.es_fellowship,
          });

          const cc2 = $form.find("select[name=es_phone_country_code]").val();
          const num2 = $form.find("input[name=es_phone_number]").val();
          if (cc2 || num2) syncQuickFormPhone(cc2, num2);

          // 保存后以新资料为准（再改所属堂时，团契下拉按新的原始值计算）
          $form.data("orig-home", formData.es_home_congregation || $form.data("orig-home"))
            .data("orig-fellowship", formData.es_fellowship);

          // 更新成功后显示"修改其他号码"，隐藏"取消"
          ensureProfileActionButtons($form);
          $form.find("#profile-edit-other").show();
          $form.find("#profile-cancel-edit").hide();
        }
      })
      .always(() => {
        setSubmitting($submit, false);
        $alsoDisable.prop('disabled', false).removeAttr('aria-disabled');
      });
  }

  function initTripleForms() {
    if (!$(S.container).length) return;

    // 加载保存的数据到表单
    loadSavedData($(S.quickForm));
    // 表单切换事件
    $(document).on("click", "#switch-to-first-time", function () {
      resetFirstTimeForm();
      switchToForm(S.firstTimeForm);
    });

    $(document).on("click", "#switch-to-profile", function () {
      resetProfileForm();
      prefillPhoneFromStorage($(S.profileForm));
      switchToForm(S.profileForm);
    });

    $(document).on("click", "#back-to-quick, #profile-to-quick", function () {
      switchToForm(S.quickForm);
    });

    $(document).on("click", "#first-to-profile", function () {
      const $firstForm = $(S.firstTimeForm);
      const $profileForm = $(S.profileForm);
      resetProfileForm();
      prefillPhoneFromStorage($profileForm);

      switchToForm(S.profileForm);
    });

    $(document).on("click", "#profile-to-first", function () {
      resetFirstTimeForm();
      switchToForm(S.firstTimeForm);
    });

    // 查找用户资料按钮事件
    $(document).on("click", "#check-profile-phone", function () {
      const $profileForm = $(S.profileForm);
      const cc = $profileForm.find("select[name=es_phone_country_code]").val();
      const num = $profileForm.find("input[name=es_phone_number]").val();
      const phone = normalizePhone(cc, num);

      if (!phone) {
        alert(T("js_phone_required"));
        return;
      }

      checkPhoneAndLoadProfile(phone, $profileForm);
    });

    // 快速签到表单提交
    $(document).on("submit", S.quickForm, function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();

      const $form = $(this);
      const $submit = getSubmitButton($form);
      if ($submit && $submit.prop('disabled')) return;

      const cc = $form.find("select[name=es_phone_country_code]").val();
      const num = $form.find("input[name=es_phone_number]").val();
      const phone = normalizePhone(cc, num);

      if (!phone) {
        alert(T("js_phone_required"));
        return;
      }

      const quickData = {
        es_phone_country_code: cc,
        es_phone_number: num
      };
      storage.set("es_attendance_form_data", { ...storage.get("es_attendance_form_data", {}), ...quickData });

      // 一按就显示"提交中/在处理中"
      setSubmitting($submit, true);

      // 让浏览器先绘制状态，再发起请求（避免"晚一拍"）
      requestAnimationFrame(() => {
        api.post("ap_check_phone_exists", { cc: cc, num: num })
          .done((resp) => {
            const exists = !!(resp && resp.success && resp.data && resp.data.exists);
            if (!exists) {
              const go = window.confirm(T("js_confirm_not_registered"));

              if (go) {
                resetFirstTimeForm();
                switchToForm(S.firstTimeForm);
                setSubmitting($submit, false);
              } else {
                // 停留在当前页面，也要结束 loading
                setSubmitting($submit, false);
                setTimeout(() => {
                  const $numInput = $form.find("input[name=es_phone_number]");
                  $numInput.focus();
                  $numInput.select && $numInput.select();
                }, 0);
              }
              return;
            }

            // 保持 loading，不关；doQuickAttendance 内部会自己 setSubmitting(true/false)
            doQuickAttendance(phone, $form, $submit);
          })
          .fail(() => {
            // 请求失败也要关掉 loading
            setSubmitting($submit, false);
            alert(T("js_check_phone_failed"));
          });
      });

    });

    // 首次登记表单提交
    $(document).on("submit", S.firstTimeForm, function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();

      const $form = $(this);
      const $submit = getSubmitButton($form);
      if ($submit && $submit.prop('disabled')) return;

      const cc = $form.find("select[name=es_phone_country_code]").val();
      const num = $form.find("input[name=es_phone_number]").val();
      const phone = normalizePhone(cc, num);

      // 一按就显示"提交中/在处理中"
      setSubmitting($submit, true);

      requestAnimationFrame(() => {
        api.post("ap_check_phone_exists", { cc: cc, num: num })
          .done((resp) => {
            const exists = !!(resp && resp.success && resp.data && resp.data.exists);

            if (exists) {
              const isNewcomerChecked = $form.find("input[name=es_is_newcomer]").is(':checked');

              if (isNewcomerChecked) {
                // 用户勾选了“新来宾” → 走完整登记以触发后端“轻确认”与新来宾表写入
                const go = window.confirm(T("js_confirm_existing_newcomer"));
                if (go) {
                  // 走 handle_submit（es_handle_attendance）：已登记的人 + 勾选新来宾 → 直接写入新来宾表
                  doFullAttendance($form, $submit);
                } else {
                  // 只签到，不写新来宾表
                  doQuickAttendanceFromFirstTime(phone, $form, $submit);
                }
              } else {
                // 未勾选新来宾，保持原有文案与行为：直接快速签到 or 回到快速签到页
                const userChoice = window.confirm(T("js_confirm_existing_check_in"));

                if (userChoice) {
                  doQuickAttendanceFromFirstTime(phone, $form, $submit);
                } else {
                  setSubmitting($submit, false);
                  const $quickForm = $(S.quickForm);
                  $quickForm.find("select[name=es_phone_country_code]").val(cc);
                  $quickForm.find("input[name=es_phone_number]").val(num);
                  switchToForm(S.quickForm);
                  displayMessage($quickForm, T("js_switched_to_quick"), "green");
                }
              }
            } else {
              // 号码未登记：仍按首次登记
              doFullAttendance($form, $submit);
            }

          })
          .fail(() => {
            // 检查失败，走保守路径；由 doFullAttendance 自行关闭
            const go = window.confirm(T("js_confirm_check_failed"));
            if (go) {
              doQuickAttendanceFromFirstTime(phone, $form, $submit); // 不改资料
            } else {
              doFullAttendance($form, $submit); // 仍走首次登记
            }
          });
      });

    });

    // 修改资料表单提交
    $(document).on("submit", S.profileForm, function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();

      const $form = $(this);
      const $submit = getSubmitButton($form);
      if ($submit && $submit.prop('disabled')) return;

      doUpdateProfile($form, $submit);
    });

    // "取消"：回到查找状态
    $(document).on("click", "#profile-cancel-edit", function () {
      const $form = $(S.profileForm);
      resetProfileForm(); // 显示查找区、隐藏资料区并清空资料字段
      // 恢复查找按钮
      const $btn = $("#check-profile-phone");
      $btn.prop("disabled", false).text(T("btn_find_profile"));
      // 隐藏"修改其他号码"按钮，保留"取消"隐藏（reset 后也不可见）
      $form.find("#profile-edit-other").hide();
    });

    // "修改其他号码"：回到查找状态以便输入新号码
    $(document).on("click", "#profile-edit-other", function () {
      const $form = $(S.profileForm);
      resetProfileForm();
      const $btn = $("#check-profile-phone");
      $btn.prop("disabled", false).text(T("btn_find_profile"));
      $(this).hide(); // 自己隐藏
    });

    // 修改所属堂 → 团契下拉换成该堂的团契
    $(document).on("change", S.profileForm + " select[name=es_home_congregation]", function () {
      rebuildProfileFellowships($(S.profileForm), true);
    });

    // 清除消息当聚焦到输入框
    $(S.container).on("focus", "input,select", function () {
      $(".es-message").remove();
    });
  }

  /* ========== 原有后台列表（保持不变） ========== */
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
        alert(T("js_load_failed"));
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
      .fail(() => { alert(T('js_update_failed')); })
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

  /* ========== 原有详情弹窗（保持不变） ========== */
  function initModal() {
    const $modal = $(S.modal);
    if (!$modal.length) return;

    const spinnerHTML = '<div class="ap-modal-loading"><div class="ap-spinner"></div></div>';

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
        return { start: s || ymd(new Date()), end: e || ymd(new Date()), congregation: $fe.find("#fe_congregation_filter").val() || "" };
      }
      const s = $("#start_date_filter").val();
      const e = $("#end_date_filter").val();
      return { start: s || ymd(new Date()), end: e || ymd(new Date()) };
    }

    let currentXhr = null;
    let lastReqId = 0;

    $(document).on("click", ".view-attendance-button", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const id = $(this).data("attendance-id");
      const dr = getDateRangeForModal();

      if (currentXhr && currentXhr.readyState !== 4) {
        try { currentXhr.abort(); } catch (_) { }
      }

      $(S.modalContent).html(spinnerHTML);
      $modal.show();

      const reqId = ++lastReqId;

      currentXhr = $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        data: {
          action: "get_attendance_info",
          nonce: esAjax.nonce,
          attendance_id: id,
          start_date_filter: dr.start,
          end_date_filter: dr.end,
          congregation: dr.congregation
        }
      })
        .done(function (html) {
          if (reqId === lastReqId) {
            $(S.modalContent).html(html);
          }
        })
        .fail(function (xhr, status) {
          if (status === "abort") return;
          $(S.modalContent).html($('<div class="ap-modal-error"/>').text(T("js_load_failed")));
        });
    });

    $(document).on("click", "#attendance-info-modal .close", function () {
      if (currentXhr && currentXhr.readyState !== 4) {
        try { currentXhr.abort(); } catch (_) { }
      }
      $modal.hide();
    });
  }

  /* ========== 启动函数 ========== */
  (function boot() {
    initForm();           // 原有单一表单
    initTripleForms();    // 新增三合一表单
    initAdmin();          // 原有后台功能
    initModal();          // 原有详情弹窗
  })();

  /* ========== 原有前台 dashboard（短代码）（保持不变） ========== */
  (function ($) {
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
        congregation: $c.find("#fe_congregation_filter").val() || "",
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
            alert(T("js_load_failed"));
          }
        })
        .fail(function () { alert(T("js_load_failed")); })
        .always(function () { feShowLoader($c, false); });
    }

    function feExportCSV($c) {
      $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        data: { action: "es_export_attendance_csv", nonce: esAjax.nonce, ...feGetFilters($c) }
      }).done(function (csv) {
        if (csv && csv.charCodeAt && csv.charCodeAt(0) === 0xFEFF) {
          csv = csv.slice(1);
        }
        const finalCsv = "\uFEFF" + csv;

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
        data: { action: "handle_member_status_update", nonce: esAjax.nonce, ids, member_action: action, congregation: $c.find("#fe_congregation_filter").val() || "" },
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
          alert(T("js_update_failed"));
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

      // 管理员换堂筛选 → 团契筛选只列该堂的团契（全部堂时按堂分组）
      $c.on("change", "#fe_congregation_filter", function () {
        const $f = $c.find("#fe_fellowship_filter");
        const sel = $(this).val();
        const all = CTX.fellowships || {};
        $f.empty().append($("<option>", { value: "", text: T("filter_all_fellowships") }));
        if (sel && all[sel]) {
          Object.keys(all[sel]).forEach((k) => $f.append($("<option>", { value: k, text: all[sel][k] })));
        } else {
          let otherText = "";
          Object.keys(all).forEach((cg) => {
            const $g = $("<optgroup>", { label: cLabel(cg) });
            Object.keys(all[cg]).forEach((k) => {
              if (k === "other") { otherText = otherText || all[cg][k]; return; }
              $g.append($("<option>", { value: k, text: all[cg][k] }));
            });
            $f.append($g);
          });
          $f.append($("<option>", { value: "other", text: otherText || "other" }));
        }
        $f.val("");
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
    });
  })(jQuery);

  /* ========== First Timers 通用处理（支持多种模式） ========== */
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
    function getCongregation($c) {
      return $c.find("#ap-ft-congregation").val() || "";
    }
    function showLoader($c, show) {
      const $l = $c.find("#ap-ft-loader");
      if (!$l.length) return;
      show ? $l.show() : $l.hide();
    }

    // 根据容器的 data-source 属性决定使用哪个 AJAX action
    function getAjaxAction($c, isExport) {
      const source = $c.attr('data-source');
      if (source === 'log') {
        return isExport ? 'ap_first_timers_log_export' : 'ap_first_timers_log_query';
      } else if (source === 'newcomers') {
        return isExport ? 'ap_newcomers_export' : 'ap_newcomers_query';
      } else {
        // 默认 first_timers
        return isExport ? 'ap_first_timers_export' : 'ap_first_timers_query';
      }
    }

    function refreshList($c) {
      // 简易转义，避免把原始数据直接插到 HTML 里
      const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

      const rng = getRange($c);
      const action = getAjaxAction($c, false);
      showLoader($c, true);

      return $.ajax({
        url: apAjaxUrl(),
        type: "POST",
        dataType: "json",
        data: { action: action, nonce: esAjax.nonce, ...rng, congregation: getCongregation($c) }
      }).done(function (resp) {
        if (resp && resp.success) {
          // 1) 后端有 html：直接用
          if (resp.data && typeof resp.data.html === "string") {
            $c.find("#ap-ft-list").html(resp.data.html);

            // 2) 后端仅给 rows：前端简易渲染（含删除按钮）
          } else if (Array.isArray(resp.data && resp.data.rows)) {
            var rows = resp.data.rows;
            if (!rows.length) {
              $c.find("#ap-ft-list").html($('<div class="ap-ft-empty"/>').text(T("js_no_records")));
            } else {
              const isNewcomers = ($c.attr('data-source') === 'newcomers'); // 只有 newcomers 才显示“删除”
              var html = rows.map(function (r) {
                var fn = esc(r.first_name || "");
                var ln = esc(r.last_name || "");
                var ph = esc(r.phone || "");
                var dt = esc(r.first_attendance_date || "");

                // 删除按钮（复合键）
                var delBtn = '';
                if (isNewcomers) {
                  delBtn =
                    '<div class="ap-ft-actions" style="margin-top:6px;">' +
                    '<button type="button" class="ap-btn ap-btn-danger ap-ft-delete" ' +
                    'data-id="' + esc(r.id || '') + '" ' +
                    'data-first-name="' + fn + '" ' +
                    'data-last-name="' + ln + '" ' +
                    'data-phone="' + ph + '" ' +
                    'data-date="' + dt + '">' +
                    esc(T('ft_remove')) +
                    '</button>' +
                    '</div>';
                }

                return (
                  '<div class="ap-ft-card">' +
                  '<div class="ap-ft-name"><strong>' + ln + ' ' + fn + '</strong></div>' +
                  '<div class="ap-ft-meta">' + esc(T('ft_first_visit', { date: r.first_attendance_date || '' })) + '</div>' +
                  (r.seen_elsewhere ? '<div class="ap-ft-meta ap-ft-seen-elsewhere">' + esc(T('seen_elsewhere', { congregation: cLabel(r.seen_elsewhere) })) + '</div>' : '') +
                  (ph ? '<div class="ap-ft-meta">📞 ' + ph + '</div>' : '') +
                  delBtn +
                  '</div>'
                );
              }).join("");
              $c.find("#ap-ft-list").html('<div class="ap-ft-grid">' + html + '</div>');
            }
          }

          // 计数与时间
          $c.attr('data-count', (resp.data && resp.data.count) ? resp.data.count : 0);
          const t = (resp.data && resp.data.generated_at) || '';
          $c.find("#ap-ft-note").text(t ? T("js_generated_at", { time: t }) : '');

        } else {
          alert(T("js_load_failed"));
        }
      }).fail(function (xhr) {
        console.error('AJAX fail', xhr && xhr.status, xhr && xhr.responseText);
        alert(T("js_load_failed"));
      }).always(function () {
        showLoader($c, false);
      });
    }


    function exportExcel($c) {
      const cnt = parseInt($c.attr('data-count') || '0', 10);
      if (!Number.isFinite(cnt) || cnt <= 0) {
        alert(T('js_export_empty'));
        return;
      }
      const rng = getRange($c);
      const action = getAjaxAction($c, true);
      const form = document.createElement("form");
      form.method = "POST";
      form.action = apAjaxUrl();
      form.style.display = "none";

      const add = (k, v) => { const i = document.createElement("input"); i.type = "hidden"; i.name = k; i.value = v; form.appendChild(i); };
      add("action", action);
      add("nonce", esAjax.nonce);
      add("start", rng.start);
      add("end", rng.end);
      add("congregation", getCongregation($c));
      add("_r", Date.now().toString());

      document.body.appendChild(form);
      form.submit();
      setTimeout(() => form.remove(), 1000);
    }

    $(function () {
      const $c = $box();
      if (!$c) return;

      $c.on("click", "#ap-ft-refresh", function (e) { e.preventDefault(); refreshList($c); });
      $c.on("click", "#ap-ft-export", function (e) { e.preventDefault(); exportExcel($c); });
      $c.on("change", "#ap-ft-start, #ap-ft-end, #ap-ft-congregation", function () { refreshList($c); });
    });
  })(jQuery);

});

(function () {
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.ap-ft-delete');
    if (!btn) return;

    const wrap = document.getElementById('ap-first-timers');
    if (!wrap) return;

    // 仅当 data-source="newcomers" 时允许删除
    if (wrap.getAttribute('data-source') !== 'newcomers') {
      return;
    }

    const id = parseInt(btn.getAttribute('data-id') || '0', 10);
    if (!id || id <= 0) return;

    const T = (window.AP && window.AP.T) || ((k) => k);
    if (!window.confirm(T('js_confirm_remove_newcomer'))) {
      return;
    }

    const nonce = wrap.getAttribute('data-nonce') || '';
    const form = new FormData();
    form.append('action', 'ap_delete_newcomer');
    form.append('id', String(id));
    form.append('_wpnonce', nonce);

    btn.disabled = true;

    try {
      const url = (window.esAjax && window.esAjax.ajaxurl) || window.ajaxurl;
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        body: form
      });
      const data = await res.json();

      if (!data || !data.success) {
        const msg = (data && data.data && data.data.message) ? data.data.message : T('js_remove_failed');
        alert(msg);
        btn.disabled = false;
        return;
      }

      // 从 DOM 移除卡片 & 更新计数
      const card = btn.closest('.ap-ft-card');
      if (card) card.remove();

      const countEl = wrap;
      const oldCount = parseInt(countEl.getAttribute('data-count') || '0', 10);
      const newCount = Math.max(oldCount - 1, 0);
      countEl.setAttribute('data-count', String(newCount));

    } catch (err) {
      console.error(err);
      alert(T('js_network_error'));
      btn.disabled = false;
    }
  });
})();
