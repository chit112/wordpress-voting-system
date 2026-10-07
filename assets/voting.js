(function () {
    'use strict';

    var config = window.CIVConfig || {};
    var strings = config.strings || {};

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function request(url, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({ 'Content-Type': 'application/json' }, options.headers || {});
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (data) {
                if (!response.ok) {
                    var message = data.message || strings.voteError || 'Request failed.';
                    throw new Error(message);
                }
                return data;
            });
        });
    }

    function startVoting(root) {
        var surveyId = root.getAttribute('data-survey-id');
        var status = root.querySelector('[data-civ-status]');
        var pair = root.querySelector('[data-civ-pair]');
        var controls = root.querySelector('[data-civ-controls]');
        var skip = root.querySelector('[data-civ-skip]');
        var progress = root.querySelector('[data-civ-progress]');
        var currentToken = '';
        var busy = false;
        var responseCount = 0;

        function showError(error, fallback) {
            status.textContent = error && error.message ? error.message : fallback;
        }

        function loadPair(message) {
            status.textContent = message || strings.loading || 'Loading…';
            pair.replaceChildren();
            controls.hidden = true;
            request(config.restRoot + 'surveys/' + encodeURIComponent(surveyId) + '/matchup')
                .then(function (matchup) {
                    currentToken = matchup.token;
                    matchup.ideas.forEach(function (idea) {
                        var button = element('button', 'civ-button civ-idea', idea.text);
                        button.type = 'button';
                        button.addEventListener('click', function () {
                            submitResponse(idea.id, false);
                        });
                        pair.appendChild(button);
                    });
                    controls.hidden = false;
                    status.textContent = '';
                    if (pair.firstChild) {
                        pair.firstChild.focus();
                    }
                })
                .catch(function (error) {
                    showError(error, strings.loadError || 'Could not load ideas.');
                    var retry = element('button', 'civ-button', strings.retry || 'Try again');
                    retry.type = 'button';
                    retry.addEventListener('click', function () {
                        loadPair();
                    });
                    pair.appendChild(retry);
                });
        }

        function submitResponse(winner, isSkip) {
            if (busy || !currentToken) {
                return;
            }
            busy = true;
            Array.prototype.forEach.call(pair.querySelectorAll('button'), function (button) {
                button.disabled = true;
            });
            skip.disabled = true;
            var payload = { token: currentToken, skip: isSkip };
            if (!isSkip) {
                payload.winner = winner;
            }
            request(config.restRoot + 'matchups/respond', {
                method: 'POST',
                body: JSON.stringify(payload)
            }).then(function () {
                currentToken = '';
                responseCount += 1;
                progress.textContent = (strings.progress || 'Responses recorded this visit: %d').replace('%d', responseCount);
                busy = false;
                skip.disabled = false;
                loadPair(strings.thanks || 'Thanks.');
            }).catch(function (error) {
                busy = false;
                skip.disabled = false;
                showError(error, strings.voteError || 'Could not record your response.');
                Array.prototype.forEach.call(pair.querySelectorAll('button'), function (button) {
                    button.disabled = false;
                });
            });
        }

        skip.addEventListener('click', function () {
            submitResponse(null, true);
        });

        var form = root.querySelector('[data-civ-submit-form]');
        var submitStatus = root.querySelector('[data-civ-submit-status]');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var textarea = form.querySelector('textarea[name="idea"]');
            var submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitStatus.textContent = '';
            request(config.restRoot + 'surveys/' + encodeURIComponent(surveyId) + '/ideas', {
                method: 'POST',
                body: JSON.stringify({ idea: textarea.value })
            }).then(function () {
                textarea.value = '';
                submitStatus.textContent = strings.pending || 'Your idea is awaiting approval.';
            }).catch(function (error) {
                submitStatus.textContent = error.message || strings.submissionError || 'Could not submit your idea.';
            }).finally(function () {
                submitButton.disabled = false;
            });
        });

        loadPair();
    }

    function startResults(root) {
        var surveyId = root.getAttribute('data-survey-id');
        var status = root.querySelector('[data-civ-results-status]');
        var output = root.querySelector('[data-civ-results-list]');
        request(config.restRoot + 'surveys/' + encodeURIComponent(surveyId) + '/results')
            .then(function (results) {
                status.textContent = results.score_note || '';
                if (!results.ranking.length && !results.historical.length) {
                    output.appendChild(element('p', '', strings.noResults || 'No results yet.'));
                    return;
                }
                if (results.ranking.length) {
                    var list = element('ol', 'civ-ranking');
                    results.ranking.forEach(function (result) {
                        var item = element('li', 'civ-ranking-item');
                        item.appendChild(element('strong', 'civ-ranking-idea', result.text));
                        item.appendChild(element('span', 'civ-ranking-score', (result.score * 100).toFixed(1) + '%'));
                        item.appendChild(element('span', 'civ-ranking-count', result.comparisons + ' ' + (strings.comparisons || 'comparisons')));
                        if (result.low_data) {
                            item.appendChild(element('span', 'civ-low-data', strings.lowData || 'Few comparisons; uncertain.'));
                        }
                        list.appendChild(item);
                    });
                    output.appendChild(list);
                }
                if (results.historical.length) {
                    output.appendChild(element('h3', 'civ-historical-heading', strings.historical || 'Historical snapshot'));
                    var historicalList = element('ol', 'civ-ranking civ-historical');
                    results.historical.forEach(function (result) {
                        var item = element('li', 'civ-ranking-item');
                        item.appendChild(element('strong', 'civ-ranking-idea', result.idea_text));
                        if (result.score !== null) {
                            item.appendChild(element('span', 'civ-ranking-score', (Number(result.score) * 100).toFixed(1) + '%'));
                        }
                        item.appendChild(element('span', 'civ-ranking-count', Number(result.comparison_count) + ' ' + (strings.comparisons || 'comparisons')));
                        if (result.source) {
                            var provenance = 'Source: ' + result.source;
                            if (result.source_timestamp) {
                                provenance += ' · ' + result.source_timestamp;
                            }
                            item.appendChild(element('span', 'civ-historical-source', provenance));
                        }
                        historicalList.appendChild(item);
                    });
                    output.appendChild(historicalList);
                }
            })
            .catch(function (error) {
                status.textContent = error.message || 'Results could not be loaded.';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-civ-voting]'), startVoting);
        Array.prototype.forEach.call(document.querySelectorAll('[data-civ-results]'), startResults);
    });
}());
