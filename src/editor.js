(function () {
    const { registerPlugin } = wp.plugins;

    const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;

    const { PanelBody, SelectControl, Button, Notice, Spinner, Dashicon, Modal } =
        wp.components;

    const { Fragment, useMemo, useState, useEffect, useRef } = wp.element;

    const dataSelect = wp.data.select;

    const dataDispatch = wp.data.dispatch;

    const { __ } = wp.i18n;

    const apiFetch = wp.apiFetch;

    const notices = dataDispatch("core/notices");

    const config = window.RRZEWebTConfig || {};

    const normalizeLanguage = (lang) => {
        if (!lang) {
            return "";
        }

        return String(lang).replace(/_/g, "-").toUpperCase();
    };

    const rawLanguages = Array.isArray(config.availableLanguages)
        ? config.availableLanguages
        : [];

    const sourceLanguage = normalizeLanguage(config.sourceLanguage);

    const uniqueLanguages = Array.from(
        new Set(rawLanguages.map(normalizeLanguage).filter(Boolean))
    );

    const baseLanguages = uniqueLanguages.length ? uniqueLanguages : ["EN"];

    const siteLanguage = normalizeLanguage(
        config.siteLanguage || sourceLanguage || baseLanguages[0]
    );

    const availableLanguages = Array.from(
        new Set([...baseLanguages, siteLanguage].filter(Boolean))
    );

    const sortedLanguages = [...availableLanguages].sort();

    const hasCredentials = !!config.hasCredentials;

    const LAST_TARGET_KEY = "rrze-webt:lastTargetLanguage";

    const loadLastTarget = () => {
        try {
            const raw = localStorage.getItem(LAST_TARGET_KEY);
            return normalizeLanguage(raw);
        } catch {
            return "";
        }
    };
    const saveLastTarget = (lang) => {
        try {
            if (lang) {
                localStorage.setItem(LAST_TARGET_KEY, normalizeLanguage(lang));
            }
        } catch {}
    };

    const defaultLanguage = siteLanguage;

    const panelTitle =
        (config.i18n && config.i18n.panelTitle) ||
        __("WEB-T Translation", "rrze-webt");

    if (
        config.nonce &&
        apiFetch &&
        typeof apiFetch.createNonceMiddleware === "function" &&
        !window.rrzeWebTNonceMiddlewareAdded
    ) {
        apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));
        window.rrzeWebTNonceMiddlewareAdded = true;
    }

    const getRequestPath = () => {
        if (config.restRoute) {
            return "/" + String(config.restRoute).replace(/^\//, "");
        }

        if (config.restUrl) {
            return config.restUrl;
        }

        return "/rrze-webt/v1/translate";
    };
    const jobsEndpoint = config.jobsEndpoint || "";
    const jobsAckEndpoint = config.jobsAckEndpoint || "";
    const pollInterval = Number(config.pollInterval) || 5000;

    const toRelativePath = (url) => {
        if (!url || typeof url !== "string") {
            return "";
        }

        try {
            const parsed = new URL(url, window.location.origin);
            let path = parsed.pathname || "";

            if (path.startsWith("/wp-json")) {
                path = path.slice("/wp-json".length) || "/";
            }

            if (!path.startsWith("/")) {
                path = `/${path}`;
            }

            if (path !== "/" && path.endsWith("/")) {
                path = path.slice(0, -1);
            }

            return path;
        } catch (error) {
            // eslint-disable-next-line no-console
            console.error("Failed to parse WEB-T endpoint URL", error);
            return "";
        }
    };

    const jobsPathBase = toRelativePath(jobsEndpoint);
    const jobsAckPathBase = toRelativePath(jobsAckEndpoint);

    const getString = (key, fallback) => {
        if (config.i18n && config.i18n[key]) {
            return config.i18n[key];
        }
        return fallback;
    };

    const jobSubmittedMsg = getString(
        "jobSubmitted",
        __(
            "Translation request submitted. Review the result before applying it in the editor.",
            "rrze-webt"
        )
    );
    const jobFailedMsg = getString(
        "jobFailed",
        __("The translation service reported a failure.", "rrze-webt")
    );
    const jobReceivedMsg = getString(
        "jobReceived",
        __("Translation received from WEB-T.", "rrze-webt")
    );
    const jobListTitle = getString(
        "jobListTitle",
        __("Translation jobs", "rrze-webt")
    );
    const jobStatusLabels = {
        pending: getString("jobStatusPending", __("Pending", "rrze-webt")),
        failed: getString("jobStatusFailed", __("Failed", "rrze-webt")),
        completed: getString(
            "jobStatusCompleted",
            __("Completed", "rrze-webt")
        ),
    };
    const jobMessageNone = getString(
        "jobMessageNone",
        __("No details available.", "rrze-webt")
    );
    const jobPendingMessage = getString(
        "jobPendingMessage",
        __(
            "Waiting for WEB-T to process this translation request.",
            "rrze-webt"
        )
    );
    const jobCompletedMessage = getString(
        "jobCompletedMessage",
        __("Translation received from WEB-T.", "rrze-webt")
    );
    const jobRequestLabel = getString(
        "jobRequestLabel",
        __("WEB-T request ID:", "rrze-webt")
    );
    const jobTokenLabel = getString(
        "jobTokenLabel",
        __("Client token:", "rrze-webt")
    );
    const jobSubmittedLabel = getString(
        "jobSubmittedLabel",
        __("Submitted:", "rrze-webt")
    );
    const jobUpdatedLabel = getString(
        "jobUpdatedLabel",
        __("Updated:", "rrze-webt")
    );
    const jobElapsedLabel = getString(
        "jobElapsedLabel",
        __("Elapsed:", "rrze-webt")
    );
    const jobElapsedSeconds = getString(
        "jobElapsedSeconds",
        __("%s seconds", "rrze-webt")
    );
    const jobElapsedMinutes = getString(
        "jobElapsedMinutes",
        __("%s minutes", "rrze-webt")
    );
    const jobElapsedHours = getString(
        "jobElapsedHours",
        __("%s hours", "rrze-webt")
    );
    const jobTitleLabel = getString(
        "jobTitleLabel",
        __("Translated title:", "rrze-webt")
    );
    const noJobsMessage = getString(
        "jobEmpty",
        __("There are no translation jobs yet.", "rrze-webt")
    );

    /**
     * Verify every parsed block and its nested type/order against the source.
     * @param {Array} source Parsed source blocks.
     * @param {Array} translated Parsed result blocks.
     * @returns {boolean} Whether Gutenberg can safely edit the matching block tree.
     */
    const validBlockTree = (source, translated) => {
        if (source.length !== translated.length) return false;
        return translated.every(
            /** @param {Object} block Result block. @param {number} index Source position. @returns {boolean} */
            (block, index) => {
                const original = source[index];
                return original.name === block.name && block.name !== "core/missing" &&
                    !!wp.blocks.getBlockType(block.name) && original.isValid !== false && block.isValid !== false &&
                    validBlockTree(original.innerBlocks || [], block.innerBlocks || []);
            }
        );
    };

    /** @returns {Object} Translation controls, job history and explicit review dialog. */
    const TranslationContent = () => {
        const lastTarget = useMemo(() => {
            const lt = loadLastTarget();
            return availableLanguages.includes(lt) ? lt : "";
        }, [availableLanguages]);

        const [targetLanguage, setTargetLanguage] = useState(() => {
            if (lastTarget) return lastTarget;
            if (sortedLanguages.includes(defaultLanguage))
                return defaultLanguage;
            if (sortedLanguages.length) return sortedLanguages[0];
            return availableLanguages[0] || "";
        });
        const [isTranslating, setIsTranslating] = useState(false);

        const [errorMessage, setErrorMessage] = useState("");

        const [currentSource, setCurrentSource] = useState(
            () => lastTarget || defaultLanguage
        );

        const [jobs, setJobs] = useState([]);
        const [isLoadingJobs, setIsLoadingJobs] = useState(false);
        const [expandedTokens, setExpandedTokens] = useState([]);
        const [reviewJob, setReviewJob] = useState(null);
        const [reviewError, setReviewError] = useState("");
        const appliedTokensRef = useRef(new Set());
        const processedTokensRef = useRef(new Set());
        const jobsFetcherRef = useRef(null);
        const jobsRef = useRef([]);

        const options = useMemo(() => {
            return (
                sortedLanguages.length ? sortedLanguages : availableLanguages
            ).map((language) => ({
                label: language.toUpperCase(),
                value: language,
            }));
        }, [sortedLanguages, availableLanguages]);

        const pendingJobs = jobsRef.current.length ? jobsRef.current : jobs;
        const hasPendingJob = pendingJobs.some(
            (job) => job && job.status === "pending"
        );

        const disabled =
            !hasCredentials ||
            !targetLanguage ||
            !currentSource ||
            options.length === 0 ||
            isTranslating ||
            hasPendingJob;
        // Subscribe so edits made while the dialog is open immediately invalidate it.
        const { postId } = wp.data.useSelect(
            /** @param {Function} select Store selector. @returns {Object} Current unsaved post. */
            (select) => {
                const editor = select("core/editor");
                return {
                    postId: editor.getCurrentPostId(),
                    editorContent: editor.getEditedPostContent(),
                    editorTitle: editor.getEditedPostAttribute("title") || "",
                };
            }, []
        );
        const jobsPath =
            postId && jobsPathBase ? `${jobsPathBase}/${postId}` : "";

        /**
         * Remember that an editor reviewed a result; this does not save the post.
         * @param {string} token Local job token.
         * @returns {void}
         */
        const ackJob = (token) => {
            if (!jobsAckPathBase || !token) {
                return;
            }

            apiFetch({
                path: `${jobsAckPathBase}/${token}`,
                method: "POST",
            }).catch(
                /** @param {Error} error Failed acknowledgement; the editor change is retained. */
                (error) => {
                    notices.createErrorNotice(getString("reviewAckError", __("The translation is in the editor, but its review could not be recorded. Save the post normally.", "rrze-webt")), { id: "rrze-webt-ack-error" });
                }
            );
        };

        useEffect(
            /** @returns {Function} Stop polling when leaving this post. */
            () => {
            if (!jobsPath) {
                setJobs([]);
                return () => {};
            }

            let isMounted = true;

            /** @param {Array} items Stored jobs; polling never changes editor content. @returns {void} */
            const handleJobs = (items) => {
                if (!Array.isArray(items)) {
                    return;
                }

                jobsRef.current = items;
                setJobs(items);

                // Keep the current source selection unless user changes it manually.

                items.forEach(
                    /** @param {Object} job Job to announce once in this editor session. @returns {void} */
                    (job) => {
                    if (!job || !job.token) {
                        return;
                    }

                    const token = job.token;

                    if (processedTokensRef.current.has(token)) {
                        return;
                    }

                    if (
                        job.status === "completed" &&
                        (job.content || job.title) &&
                        !job.acknowledged
                    ) {
                        processedTokensRef.current.add(token);
                        notices.createInfoNotice(jobReceivedMsg, {
                            id: `rrze-webt-job-success-${token}`,
                        });
                        return;
                    }

                    if (job.status === "failed" && !job.acknowledged) {
                        const message = job.message || jobFailedMsg;
                        processedTokensRef.current.add(token);
                        notices.createErrorNotice(message, {
                            id: `rrze-webt-job-failed-${token}`,
                            isDismissible: true,
                        });
                    }
                });
            };

            /** @returns {Promise<void>} Refresh history without applying or acknowledging jobs. */
            const fetchJobs = () => {
                if (!jobsRef.current.length) {
                    setIsLoadingJobs(true);
                }

                return apiFetch({
                    path: jobsPath,
                    method: "GET",
                })
                    .then((response) => {
                        if (!isMounted) {
                            return;
                        }

                        if (response && Array.isArray(response.jobs)) {
                            handleJobs(response.jobs);
                        } else {
                            setJobs([]);
                        }
                    })
                    .catch((error) => {
                        if (isMounted) {
                            // eslint-disable-next-line no-console
                            console.error("WEB-T jobs fetch failed", error);
                        }
                    })
                    .finally(() => {
                        if (isMounted) {
                            setIsLoadingJobs(false);
                        }
                    });
            };

            jobsFetcherRef.current = fetchJobs;

            fetchJobs();

            const intervalId = window.setInterval(fetchJobs, pollInterval);

            return () => {
                isMounted = false;
                jobsFetcherRef.current = null;
                jobsRef.current = [];
                window.clearInterval(intervalId);
            };
        }, [jobsPath]);

        const transformTimestamp = (timestamp) => {
            if (!timestamp) {
                return "";
            }

            try {
                return new Date(timestamp * 1000).toLocaleString();
            } catch (error) {
                return "";
            }
        };

        const substituteValue = (template, value) =>
            template.replace("%s", String(value));

        const formatElapsed = (job) => {
            if (!job || !job.submittedAt) {
                return "";
            }

            const submitted = Number(job.submittedAt) || 0;
            const updated = Number(job.updatedAt) || submitted;

            if (submitted <= 0) {
                return "";
            }

            const nowSeconds = Math.round(Date.now() / 1000);
            const reference = job.status === "pending" ? nowSeconds : updated;
            const diff = Math.max(0, reference - submitted);

            if (diff >= 3600) {
                const hours = Math.round(diff / 3600);
                return substituteValue(jobElapsedHours, hours);
            }

            if (diff >= 60) {
                const minutes = Math.round(diff / 60);
                return substituteValue(jobElapsedMinutes, minutes);
            }

            return substituteValue(jobElapsedSeconds, diff);
        };

        const toggleJobDetails = (token) => {
            setExpandedTokens((prev) => {
                if (prev.includes(token)) {
                    return prev.filter((current) => current !== token);
                }

                return [...prev, token];
            });
        };

        const sourceOptions = useMemo(() => {
            return availableLanguages.map((language) => ({
                label: language,
                value: language,
            }));
        }, [availableLanguages]);

        /** @returns {Object|Array} Job history with an explicit review action. */
        const renderJobs = () => {
            if (isLoadingJobs && !jobs.length) {
                return wp.element.createElement(
                    Notice,
                    {
                        status: "info",
                        isDismissible: false,
                        key: "rrze-webt-jobs-loading",
                    },
                    wp.element.createElement(Spinner, {
                        style: { marginRight: "8px" },
                    }),
                    __("Fetching translation jobs…", "rrze-webt")
                );
            }

            if (!jobs.length) {
                return wp.element.createElement(
                    Notice,
                    {
                        status: "info",
                        isDismissible: false,
                        key: "rrze-webt-jobs-empty",
                    },
                    noJobsMessage
                );
            }

            return jobs.map(
                /** @param {Object} job Stored job. @returns {Object} Job card. */
                (job) => {
                const token = job.token || Math.random().toString(36).slice(2);
                const status = job.status || "pending";
                const noticeStatus =
                    status === "failed"
                        ? "error"
                        : status === "completed"
                        ? "success"
                        : "info";
                const statusLabel = jobStatusLabels[status] || status;
                const submitted = transformTimestamp(job.submittedAt);
                const updated = transformTimestamp(job.updatedAt);
                const elapsed = formatElapsed(job);
                const sourceLang = job.sourceLanguage
                    ? job.sourceLanguage.toUpperCase()
                    : "";
                const targetLang = job.targetLanguage
                    ? job.targetLanguage.toUpperCase()
                    : "";
                const languageLabel =
                    sourceLang && targetLang
                        ? `${sourceLang} → ${targetLang}`
                        : targetLang || "";
                const requestId = job.requestId || "";
                const translatedTitle = job.title || "";
                const expanded = expandedTokens.includes(token);
                const toggleLabel = expanded
                    ? __("Hide details", "rrze-webt")
                    : __("Show details", "rrze-webt");
                const detailsIcon = expanded
                    ? "arrow-down-alt2"
                    : "arrow-right-alt2";

                let message = job.message ? job.message.trim() : "";

                if (!message) {
                    if (status === "pending") {
                        message = jobPendingMessage;
                    } else if (status === "completed") {
                        message = jobCompletedMessage;
                    } else if (status === "failed") {
                        message = jobFailedMsg;
                    } else {
                        message = jobMessageNone;
                    }
                }

                return wp.element.createElement(
                    Notice,
                    {
                        status: noticeStatus,
                        isDismissible: false,
                        key: `rrze-webt-job-${token}`,
                    },
                    wp.element.createElement(
                        "div",
                        {
                            style: {
                                display: "flex",
                                alignItems: "center",
                                gap: "8px",
                                flexWrap: "wrap",
                            },
                        },
                        `${statusLabel}`,
                        languageLabel ? ` · ${languageLabel}` : "",
                        wp.element.createElement(
                            Button,
                            {
                                isSmall: true,
                                isLink: true,
                                onClick: () => toggleJobDetails(token),
                                "aria-label": toggleLabel,
                                "aria-expanded": expanded,
                                className: "rrze-webt-details-toggle",
                            },
                            wp.element.createElement(Dashicon, {
                                icon: detailsIcon,
                            }),
                            wp.element.createElement(
                                "span",
                                { className: "screen-reader-text" },
                                toggleLabel
                            )
                        )
                    ),
                    status === "completed" && wp.element.createElement(
                        Button,
                        {
                            variant: "secondary",
                            className: "rrze-webt-review-button",
                            onClick:
                                /** @returns {void} Open an inert preview without editing the post. */
                                () => { setReviewError(""); setReviewJob(job); },
                        },
                        getString("reviewTranslation", __("Review translation", "rrze-webt"))
                    ),
                    expanded &&
                        wp.element.createElement(
                            Fragment,
                            null,
                            submitted
                                ? wp.element.createElement(
                                      "div",
                                      null,
                                      jobSubmittedLabel,
                                      " ",
                                      submitted
                                  )
                                : null,
                            updated
                                ? wp.element.createElement(
                                      "div",
                                      null,
                                      jobUpdatedLabel,
                                      " ",
                                      updated
                                  )
                                : null,
                            elapsed
                                ? wp.element.createElement(
                                      "div",
                                      null,
                                      jobElapsedLabel,
                                      " ",
                                      elapsed
                                  )
                                : null,
                            requestId
                                ? wp.element.createElement(
                                      "div",
                                      null,
                                      jobRequestLabel,
                                      " ",
                                      requestId
                                  )
                                : null,
                            translatedTitle
                                ? wp.element.createElement(
                                      "div",
                                      null,
                                      jobTitleLabel,
                                      " ",
                                      translatedTitle
                                  )
                                : null,
                            token
                                ? wp.element.createElement(
                                      "div",
                                      null,
                                      jobTokenLabel,
                                      " ",
                                      token
                                  )
                                : null,
                            wp.element.createElement("div", null, message)
                        )
                );
            });
        };

        /**
         * Recheck the live editor and the registered Gutenberg blocks before application.
         * @param {Object} job Result selected by the user.
         * @returns {{error: string, blocks?: Array}} A blocking reason or validated blocks.
         */
        const validateReview = (job) => {
            const editor = dataSelect("core/editor");
            if (!job || job.status !== "completed" || typeof job.sourceContent !== "string" || typeof job.sourceTitle !== "string") {
                return { error: getString("reviewLegacy", __("This older job has no source snapshot. Request a new translation before applying it.", "rrze-webt")) };
            }
            if (Number(job.postId) !== Number(editor.getCurrentPostId()) ||
                job.sourceContent !== editor.getEditedPostContent() ||
                job.sourceTitle !== (editor.getEditedPostAttribute("title") || "")) {
                return { error: getString("reviewChanged", __("The title or content has changed since this translation was requested. Request a new translation to keep your changes.", "rrze-webt")) };
            }
            try {
                const before = wp.blocks.parse(job.sourceContent);
                const after = wp.blocks.parse(job.content);
                if (!after.length || !validBlockTree(before, after)) throw new Error("Invalid block tree");
                return { error: "", blocks: after };
            } catch {
                return { error: getString("reviewInvalid", __("The translation contains invalid or unsupported blocks. It cannot be applied safely.", "rrze-webt")) };
            }
        };

        /** @returns {void} Close the dialog without changing the post. */
        const closeReview = () => { setReviewJob(null); setReviewError(""); };

        /**
         * Apply one reviewed result as an undoable, unsaved Gutenberg edit.
         * @returns {void}
         */
        const applyReviewedTranslation = () => {
            if (!reviewJob || appliedTokensRef.current.has(reviewJob.token)) return;
            const validation = validateReview(reviewJob);
            if (validation.error) { setReviewError(validation.error); return; }
            // One edit updates both the block model and title, preserving normal undo.
            dataDispatch("core/editor").editPost({
                title: reviewJob.title,
                content: reviewJob.content,
                blocks: validation.blocks,
            });
            appliedTokensRef.current.add(reviewJob.token);
            ackJob(reviewJob.token);
            closeReview();
            notices.createSuccessNotice(getString("reviewApplied", __("Translation applied in the editor. Review and save the post when ready.", "rrze-webt")), { id: "rrze-webt-applied" });
        };

        /** @returns {Object|null} Plain-text comparison; provider HTML is never executed. */
        const renderReview = () => {
            if (!reviewJob) return null;
            // The subscribed values above make validation respond to editor changes.
            const validation = validateReview(reviewJob);
            const error = reviewError || validation.error;
            return wp.element.createElement(
                Modal,
                { title: getString("reviewTranslation", __("Review translation", "rrze-webt")), onRequestClose: closeReview, className: "rrze-webt-review-modal" },
                wp.element.createElement("p", null, getString("reviewInstructions", __("Compare the text before replacing the editor title and content. Formatting is checked separately. Saving remains a separate WordPress action.", "rrze-webt"))),
                wp.element.createElement("div", { className: "rrze-webt-review-columns" },
                    wp.element.createElement("section", null,
                        wp.element.createElement("h3", null, getString("reviewOriginal", __("Submitted original", "rrze-webt"))),
                        wp.element.createElement("h4", null, reviewJob.sourceTitle || getString("reviewNoTitle", __("(No title)", "rrze-webt"))),
                        wp.element.createElement("div", { className: "rrze-webt-text-preview" }, reviewJob.sourcePreview || "")
                    ),
                    wp.element.createElement("section", null,
                        wp.element.createElement("h3", null, getString("reviewResult", __("Translation", "rrze-webt"))),
                        wp.element.createElement("h4", null, reviewJob.title || getString("reviewNoTitle", __("(No title)", "rrze-webt"))),
                        wp.element.createElement("div", { className: "rrze-webt-text-preview" }, reviewJob.preview || "")
                    )
                ),
                error && wp.element.createElement(Notice, { status: "warning", isDismissible: false }, error),
                wp.element.createElement("div", { className: "rrze-webt-review-actions" },
                    wp.element.createElement(Button, { variant: "secondary", onClick: closeReview }, getString("reviewCancel", __("Cancel", "rrze-webt"))),
                    wp.element.createElement(Button, {
                        variant: "primary", onClick: applyReviewedTranslation,
                        disabled: !!error || appliedTokensRef.current.has(reviewJob.token),
                    }, getString("reviewApply", __("Apply in editor", "rrze-webt")))
                )
            );
        };

        /**
         * Submit editor content once and refresh persisted job state on every outcome.
         *
         * A browser abort only stops waiting; it cannot cancel provider processing.
         * @returns {void}
         */
        const translateContent = () => {
            if (!hasCredentials) {
                setErrorMessage(
                    getString(
                        "missingCredentials",
                        __(
                            "Please configure the WEB-T application name and password.",
                            "rrze-webt"
                        )
                    )
                );
                return;
            }

            const postContent =
                dataSelect("core/editor").getEditedPostContent();
            const postTitle =
                dataSelect("core/editor").getEditedPostAttribute("title");

            if (!postContent || postContent.trim() === "") {
                setErrorMessage(
                    __("Nothing to translate. Add content first.", "rrze-webt")
                );
                return;
            }

            setIsTranslating(true);
            setErrorMessage("");

            /** @returns {void} Clear the local submission indicator. */
            const finalize = () => {
                setIsTranslating(false);
            };

            const clientTimeoutMs =
                Number(config.clientRequestTimeoutMs) || 30000;

            const controller =
                typeof AbortController !== "undefined"
                    ? new AbortController()
                    : null;
            const timeoutId = controller
                ? setTimeout(() => controller.abort(), clientTimeoutMs)
                : null;

            apiFetch({
                path: getRequestPath(),
                method: "POST",
                data: {
                    content: postContent,
                    title: postTitle,
                    target_language: targetLanguage,
                    source_language: currentSource,
                    post_id: postId,
                },
                signal: controller ? controller.signal : undefined,
            })
                /** @param {Object} response Persisted asynchronous job response. */
                .then((response) => {
                    if (response && response.token && response.status) {
                        notices.createInfoNotice(jobSubmittedMsg, {
                            id: "rrze-webt-job-submitted",
                        });
                        finalize();
                    } else {
                        throw new Error(
                            getString(
                                "errorNotice",
                                __("Translation failed.", "rrze-webt")
                            )
                        );
                    }
                })
                /** @param {Error} error Transport or REST failure. */
                .catch((error) => {
                    const aborted =
                        error &&
                        (error.name === "AbortError" ||
                            /aborted/i.test(error.message || ""));
                    const message = aborted
                        ? __(
                              "The browser stopped waiting. The translation may still be running; check the job history.",
                              "rrze-webt"
                          )
                        : error && error.message
                        ? error.message
                        : getString(
                              "errorNotice",
                              __("Translation failed.", "rrze-webt")
                          );
                    setErrorMessage(message);
                    notices?.createErrorNotice?.(message, {
                        id: "rrze-webt-error",
                        isDismissible: true,
                    });
                })
                /** @returns {void} Refresh even after an error or a client-side abort. */
                .finally(() => {
                    if (timeoutId) clearTimeout(timeoutId);
                    if (jobsFetcherRef.current) {
                        jobsFetcherRef.current();
                    }
                    finalize();
                });
        };

        return wp.element.createElement(
            Fragment,
            null,
            wp.element.createElement(
                "h3",
                { style: { marginTop: "0" } },
                jobListTitle
            ),
            renderJobs(),
            renderReview(),
            !hasCredentials &&
                wp.element.createElement(
                    Notice,
                    { status: "warning", isDismissible: false },
                    getString(
                        "missingCredentials",
                        __(
                            "Please provide the WEB-T application name and password to enable translations.",
                            "rrze-webt"
                        )
                    )
                ),
            hasPendingJob &&
                wp.element.createElement(
                    Notice,
                    { status: "info", isDismissible: false },
                    getString(
                        "jobInProgress",
                        __(
                            "A translation is in progress. Please wait until it finishes.",
                            "rrze-webt"
                        )
                    )
                ),
            wp.element.createElement(SelectControl, {
                label: getString(
                    "sourceLabel",
                    __("Source language", "rrze-webt")
                ),
                value: currentSource,
                options: sourceOptions,
                onChange: (value) => setCurrentSource(value),
                className: "rrze-webt-source-select",
            }),
            errorMessage &&
                wp.element.createElement(
                    Notice,
                    {
                        status: "error",
                        isDismissible: true,
                        onRemove: () => setErrorMessage(""),
                    },
                    errorMessage
                ),
            wp.element.createElement(SelectControl, {
                label: getString(
                    "languageLabel",
                    __("Target language", "rrze-webt")
                ),
                value: targetLanguage,
                options,
                onChange: (value) => {
                    const v = normalizeLanguage(value);
                    setTargetLanguage(v);
                    saveLastTarget(v);
                },
                disabled: options.length === 0,
            }),
            wp.element.createElement(
                Button,
                {
                    isPrimary: true,
                    isBusy: isTranslating,
                    disabled,
                    onClick: translateContent,
                    style: { marginTop: "12px" },
                },
                isTranslating
                    ? getString("inProgress", __("Translating…", "rrze-webt"))
                    : getString(
                          "translateButton",
                          __("Translate content", "rrze-webt")
                      )
            )
        );
    };

    const Sidebar = () =>
        wp.element.createElement(
            Fragment,
            null,
            wp.element.createElement(
                PluginSidebarMoreMenuItem,
                { target: "rrze-webt-sidebar" },
                panelTitle
            ),
            wp.element.createElement(
                PluginSidebar,
                { name: "rrze-webt-sidebar", title: panelTitle },
                wp.element.createElement(
                    PanelBody,
                    { title: panelTitle, initialOpen: true },
                    wp.element.createElement(TranslationContent, null)
                )
            )
        );

    registerPlugin("rrze-webt-translator", {
        icon: "translation",
        render: Sidebar,
    });
})();
