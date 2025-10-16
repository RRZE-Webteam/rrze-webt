(function () {
    const { registerPlugin } = wp.plugins;

    const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;

    const { PanelBody, SelectControl, Button, Notice, Spinner, Dashicon } =
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
            "Translation request submitted. The translated content will appear automatically once ready.",
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
        const postId = dataSelect("core/editor").getCurrentPostId();
        const jobsPath =
            postId && jobsPathBase ? `${jobsPathBase}/${postId}` : "";

        const ackJob = (token) => {
            if (!jobsAckPathBase || !token) {
                return;
            }

            apiFetch({
                path: `${jobsAckPathBase}/${token}`,
                method: "POST",
            }).catch((error) => {
                // eslint-disable-next-line no-console
                console.error("WEB-T acknowledge job failed", error);
            });
        };

        useEffect(() => {
            if (!jobsPath) {
                setJobs([]);
                return () => {};
            }

            let isMounted = true;

            const handleJobs = (items) => {
                if (!Array.isArray(items)) {
                    return;
                }

                jobsRef.current = items;
                setJobs(items);

                // Keep the current source selection unless user changes it manually.

                items.forEach((job) => {
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
                        const update = {};

                        if (job.content) {
                            update.content = job.content;
                        }

                        if (job.title) {
                            update.title = job.title;
                        }

                        if (Object.keys(update).length) {
                            dataDispatch("core/editor").editPost(update);
                        }

                        processedTokensRef.current.add(token);
                        notices.createSuccessNotice(jobReceivedMsg, {
                            id: `rrze-webt-job-success-${token}`,
                        });
                        ackJob(token);
                        return;
                    }

                    if (job.status === "failed" && !job.acknowledged) {
                        const message = job.message || jobFailedMsg;
                        processedTokensRef.current.add(token);
                        notices.createErrorNotice(message, {
                            id: `rrze-webt-job-failed-${token}`,
                            isDismissible: true,
                        });
                        ackJob(token);
                    }
                });
            };

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

            return jobs.map((job) => {
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

            const finalize = () => {
                setIsTranslating(false);
            };

            const clientTimeoutMs =
                Number(config.clientRequestTimeoutMs) || 20000;

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
                .then((response) => {
                    if (response && response.translation) {
                        if (typeof response.translation === "string") {
                            dataDispatch("core/editor").editPost({
                                content: response.translation,
                            });
                        } else {
                            const update = {};

                            if (response.translation.content) {
                                update.content = response.translation.content;
                            }

                            if (response.translation.title) {
                                update.title = response.translation.title;
                            }

                            if (Object.keys(update).length) {
                                dataDispatch("core/editor").editPost(update);
                            }
                        }
                        notices.createSuccessNotice(
                            getString(
                                "successNotice",
                                __(
                                    "Content translated successfully.",
                                    "rrze-webt"
                                )
                            ),
                            { id: "rrze-webt-success" }
                        );
                        finalize();
                    } else if (response && response.jobId) {
                        notices.createInfoNotice(jobSubmittedMsg, {
                            id: "rrze-webt-job-submitted",
                        });
                        if (jobsFetcherRef.current) {
                            jobsFetcherRef.current();
                        }
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
                .catch((error) => {
                    const aborted =
                        error &&
                        (error.name === "AbortError" ||
                            /aborted/i.test(error.message || ""));
                    const message = aborted
                        ? __(
                              "Request cancelled due to client-side timeout.",
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
                .finally(() => {
                    if (timeoutId) clearTimeout(timeoutId);
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
