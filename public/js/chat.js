// The chat page: messages, tabs for private chats, and the people list.
// The server's values come from data attributes on #chat (see base_chat.html)

var MESSAGES_INTERVAL = 1000;
var MESSAGES_HIDDEN_INTERVAL = 8000;
// The list changes slowly, so every 15 seconds is enough
var PEOPLE_INTERVAL = 15000;
// Matches the breakpoint in chat.css
var NARROW_SCREEN = '(max-width: 1100px)';
// The MapChat bot's made-up country, at 0°, 0° (a code free for private use,
// so Intl has no name for it)
var NULL_ISLAND = 'XN';
// Scrolling down to new messages; none for people who ask for less motion
var SCROLL_DURATION = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 400;

$(document).ready(function () {

    var myId = $('#chat').data('my-id');

    // The tab being shown: "everyone", or the id of the person in a private chat
    var activeConversation = 'everyone';

    // Ids of the people online, from the last people list
    var onlineIds = {};

    // Plays a leaving animation, then removes the element. One that isn't
    // shown (e.g. in the closed people drawer) never animates, so it goes at once
    function fadeOut(el, className) {
        if (!el.is(':visible')) {
            el.remove();
            return;
        }
        el.addClass(className).one('animationend', function () {
            el.remove();
        });
    }

    // Run a change that resizes or removes tabs, then let the tabs that moved
    // slide to their new place instead of jumping. translate, not transform,
    // which would undo the tabs' flip
    function slideTabs(change) {
        var tabs = $('#chat-tabs .chat-tab');
        var before = tabs.map(function () { return this.getBoundingClientRect().left; }).get();

        change();

        var moved = tabs.filter(function (i) {
            if (!this.isConnected) return false;
            var shift = before[i] - this.getBoundingClientRect().left;
            if (Math.round(shift) === 0) return false;
            this.style.translate = shift + 'px 0';
            return true;
        });

        requestAnimationFrame(function () {
            moved.addClass('is-shifting').css('translate', '').one('transitionend', function () {
                $(this).removeClass('is-shifting');
            });
        });
    }

    // Tabs from the history just appear; ones opened later ease in
    var tabsReady = false;

    function tabFor(conversation) {
        return $('#chat-tabs .chat-tab[data-conversation="' + conversation + '"]').not('.is-leaving');
    }

    function addTab(conversation, name) {
        var tab = tabFor(conversation);
        if (!tab.length) {
            tab = $('<a class="chat-tab" href="#"></a>')
                .attr('data-conversation', conversation)
                .append($('<span class="chat-tab__online" title="Online"></span>').prop('hidden', !onlineIds[conversation]))
                .append($('<span class="chat-tab__name"></span>').text(name).attr('title', name))
                .append('<span class="chat-tab__unread" hidden></span>')
                .append('<span class="chat-tab__close" title="Close">&times;</span>')
                .toggleClass('is-entering', tabsReady);
            $('#chat-tabs').append(tab);
        }
        return tab;
    }

    function showConversation(conversation) {
        activeConversation = String(conversation);

        $('#chat-tabs .chat-tab').removeClass('is-active');
        var tab = tabFor(activeConversation).addClass('is-active');
        slideTabs(function () {
            tab.find('.chat-tab__unread').prop('hidden', true).text('');
        });
        tab[0].scrollIntoView({block: 'nearest', inline: 'nearest'});

        $('#chat-list .chat').each(function () {
            $(this).toggle(String($(this).data('conversation')) === activeConversation);
        });

        // Restart the fade (reading offsetWidth makes the browser see the removal)
        var list = $('#chat-list').removeClass('is-switching');
        list[0].offsetWidth;
        list.addClass('is-switching');

        var name = tab.find('.chat-tab__name').text();
        var isPrivate = activeConversation !== 'everyone';

        $('#private-note').prop('hidden', !isPrivate);
        $('#private-note-text').text('Private chat with ' + name);
        $('#textarea-send').attr('placeholder', isPrivate ? 'Message ' + name : 'Type your message');
        addDayDividers();
        $('#chat-list').scrollTop($('#chat-list').prop('scrollHeight'));
        newWhileAway = 0;
    }

    // Uploaded photos fade in over a soft circle once loaded; letter avatars
    // are inline images and show at once
    function fadeInPhotos(images) {
        images.each(function () {
            var img = this;
            if (img.complete || img.src.indexOf('data:') === 0) return;

            var frame = $(img).parent().addClass('is-loading');
            $(img).one('load error', function () {
                frame.removeClass('is-loading');
            });
        });
    }

    // The time where the sender was. Without their time zone, an estimate from
    // longitude (an hour per 15 degrees), marked with "~"
    function senderTime(date, timezone, lng) {
        var format = {hour: 'numeric', minute: '2-digit'};

        if (timezone) {
            return {
                text: date.toLocaleTimeString([], Object.assign({timeZone: timezone}, format)),
                hour: Number(date.toLocaleString('en-US', {hour: 'numeric', hourCycle: 'h23', timeZone: timezone}))
            };
        }

        var shifted = new Date(date.getTime() + Math.round(lng / 15) * 3600000);
        return {
            text: '~' + shifted.toLocaleTimeString([], Object.assign({timeZone: 'UTC'}, format)),
            hour: shifted.getUTCHours()
        };
    }

    // Older browsers lack Intl.DisplayNames; they show the country code instead
    var countryNames = window.Intl && Intl.DisplayNames ? new Intl.DisplayNames(['en'], {type: 'region'}) : null;

    // Each message shows its time; hovering it slides to the sender's own time.
    // Also names the sender's country, shown when hovering the city
    function showTimes(chats) {
        chats.find('.chat__country').each(function () {
            var code = $(this).data('country');
            if (code === NULL_ISLAND) {
                $(this).text('Republic of Null Island');
            } else {
                $(this).text(countryNames ? countryNames.of(code) : code);
            }
        });

        chats.each(function () {
            var date = new Date($(this).find('time').attr('datetime'));
            $(this).find('time').text(date.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'}));

            var tip = $(this).find('.chat__sender-time');
            if (!tip.length) return;

            var info = $(this).find('.chat__time');
            // Always midnight on Null Island, where the time zones meet
            var local = info.data('country') === NULL_ISLAND
                ? {text: '00:00', hour: 0}
                : senderTime(date, info.data('timezone'), parseFloat(info.data('lng')));
            var day = local.hour >= 6 && local.hour < 18;

            tip.find('.chat__daylight').text(day ? 'wb_sunny' : 'nights_stay').addClass(day ? 'chat__daylight--day' : 'chat__daylight--night');
            tip.find('span').text(local.text);
        });
    }

    function dayLabel(date) {
        var yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);

        if (date.toDateString() === new Date().toDateString()) return 'Today';
        if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';
        return date.toLocaleDateString([], {month: 'short', day: 'numeric'});
    }

    // A divider wherever the day changes in the conversation being shown
    function addDayDividers() {
        $('#chat-list .day-divider').remove();

        var lastDay = null;
        $('#chat-list .chat').each(function () {
            if (String($(this).data('conversation')) !== activeConversation) return;

            var date = new Date($(this).find('time').attr('datetime'));
            if (date.toDateString() !== lastDay) {
                $('<div class="day-divider"><span class="day-divider__label"></span></div>').insertBefore(this)
                    .find('span').text(dayLabel(date));
                lastDay = date.toDateString();
            }
        });
    }

    // Put new messages in their tab and count the ones in other tabs
    function placeMessages(chats) {
        chats.each(function () {
            var conversation = String($(this).data('conversation'));
            var tab = addTab(conversation, $(this).data('partner'));

            if (conversation !== activeConversation) {
                $(this).hide();
                var unread = tab.find('.chat-tab__unread');
                slideTabs(function () {
                    unread.toggleClass('is-entering', unread.prop('hidden'))
                        .text((parseInt(unread.text(), 10) || 0) + 1).prop('hidden', false);
                });
            }
        });
    }

    showTimes($('#chat-list .chat'));
    fadeInPhotos($('#chat-list .chat__photo'));
    $('#chat-list .chat').each(function () {
        addTab(String($(this).data('conversation')), $(this).data('partner'));
    });
    showConversation('everyone');
    tabsReady = true;

    $('#chat-tabs').on('click', '.chat-tab', function (e) {
        e.preventDefault();
        showConversation($(this).data('conversation'));
    });

    // A mouse wheel scrolls the tabs sideways
    $('#chat-tabs').on('wheel', function (e) {
        this.scrollLeft += e.originalEvent.deltaY;
    });

    $('#chat-tabs').on('click', '.chat-tab__close', function (e) {
        e.stopPropagation();
        var tab = $(this).closest('.chat-tab');
        if (tab.hasClass('is-active')) {
            showConversation('everyone');
        }
        tab.addClass('is-leaving').one('animationend', function () {
            slideTabs(function () { tab.remove(); });
        });
    });

    function openPrivateChat(userId, name) {
        addTab(userId, name);
        showConversation(userId);
        $('#textarea-send').focus();
    }

    $('#chat-list').on('click', '.chat__name--can-message', function () {
        openPrivateChat($(this).data('user-id'), $(this).data('user-name'));
    });

    function timeAgo(minutes) {
        if (minutes < 60) return minutes + ' min ago';
        return Math.floor(minutes / 60) + ' h ago';
    }

    function personRow(person) {
        var isMe = person.id === myId;

        var row = $('<div class="person"></div>')
            .toggleClass('is-online', person.is_online == 1)
            .toggleClass('person--can-message', !isMe)
            .attr('data-user-id', person.id)
            .attr('title', isMe ? '' : 'Chat privately with ' + person.name)
            .attr('data-tip-side', 'left'); // over the map, not the rows above

        $('<div class="person__avatar"></div>')
            .append($('<img class="person__photo" alt="">').attr('src', person.avatar_src))
            .appendTo(row);
        // Online: flag and city. Recently here: how long ago
        var detail = $('<span class="person__detail"></span>');
        if (person.is_online != 1) {
            detail.text(timeAgo(person.minutes_ago));
        } else {
            if (person.country) {
                detail.append($('<span class="fi"></span>').addClass('fi-' + person.country.toLowerCase()));
            }
            detail.append($('<span class="person__city"></span>').text(person.city || ''));
        }

        $('<div class="person__text"></div>')
            .append($('<span class="person__name"></span>').text(person.name + (isMe ? ' (you)' : '')))
            .append(detail)
            .appendTo(row);

        return row.data('person', person);
    }

    function showPeople(people) {
        var online = people.filter(function (p) { return p.is_online == 1; });
        var recent = people.filter(function (p) { return p.is_online != 1; });

        // You first, then everyone else by name
        online.sort(function (a, b) {
            return (b.id === myId) - (a.id === myId) || a.name.localeCompare(b.name);
        });

        // The rows are replaced, so a hovered row never gets its mouseleave
        $('.map-pin').removeClass('is-highlighted');

        // A green dot on the tab of each private chat partner who's online;
        // it fades only when that changes
        onlineIds = {};
        online.forEach(function (p) { onlineIds[p.id] = true; });
        $('#chat-tabs .chat-tab__online').each(function () {
            var dot = $(this);
            var isOnline = !!onlineIds[dot.closest('.chat-tab').data('conversation')];
            if (isOnline === !dot.prop('hidden')) return;

            if (isOnline) {
                dot.prop('hidden', false);
            } else {
                dot.addClass('is-leaving').one('animationend', function () {
                    dot.prop('hidden', true).removeClass('is-leaving');
                });
            }
        });

        $('#online-count, #people-toggle-count').text(online.length);
        fillPeople($('#people-online'), online);
        $('#recent-heading').prop('hidden', !recent.length);
        fillPeople($('#people-recent'), recent);
        fadeInPhotos($('#people img'));
    }

    // The rows are rebuilt each round, but only people new to the section
    // ease in; those gone ease out where they were. Moving between online and
    // recent counts as both, so the green dot fades with the row
    function fillPeople(section, people) {
        var old = {};
        section.children('.person').not('.is-leaving').each(function (index) {
            old[$(this).attr('data-user-id')] = {row: $(this), index: index, top: this.getBoundingClientRect().top};
        });
        var tops = {};
        Object.keys(old).forEach(function (id) { tops[id] = old[id].top; });

        var rows = people.map(function (person) {
            var row = personRow(person).toggleClass('is-entering', !old[person.id]);
            delete old[person.id];
            return row;
        });
        section.empty().append(rows);

        Object.keys(old).forEach(function (id) {
            var gone = old[id];
            var next = section.children().eq(gone.index);
            if (next.length) {
                gone.row.insertBefore(next);
            } else {
                section.append(gone.row);
            }
            fadeOut(gone.row, 'is-leaving');
        });

        slideToNewPlaces(section, tops);
    }

    // Rows that were already there start where they were and slide to their
    // new place, so someone joining pushes the list down smoothly
    function slideToNewPlaces(section, tops) {
        var moved = section.children('.person').not('.is-entering, .is-leaving').filter(function () {
            var before = tops[$(this).attr('data-user-id')];
            return before !== undefined && Math.round(before - this.getBoundingClientRect().top) !== 0;
        });

        moved.each(function () {
            var shift = tops[$(this).attr('data-user-id')] - this.getBoundingClientRect().top;
            this.style.transform = 'translateY(' + shift + 'px)';
        });

        // The next frame, so the browser draws them at the old place first
        requestAnimationFrame(function () {
            moved.addClass('is-shifting').css('transform', '').one('transitionend', function () {
                $(this).removeClass('is-shifting');
            });
        });
    }

    (function loadPeople() {
        postForm('/app/get-people', {timezone: Intl.DateTimeFormat().resolvedOptions().timeZone})
            .then(function (response) {
                showPeople(response.data);
            })
            .catch(function () {
                // Try again on the next round
            })
            .finally(function () {
                setTimeout(loadPeople, PEOPLE_INTERVAL);
            });
    })();

    // Slide the panels in
    $('body').addClass('is-ready');

    // A drawer on narrow screens, a column that can be closed on wide ones
    var narrowScreen = window.matchMedia(NARROW_SCREEN);

    function setPeopleOpen(open) {
        var apply = function () {
            if (narrowScreen.matches) {
                $('#people').toggleClass('is-open', open);
            } else {
                $('body').toggleClass('is-people-closed', !open);
            }
        };

        if (open) {
            apply();
            return;
        }

        // Slide out first, then hide
        $('#people').addClass('is-closing').one('animationend', function () {
            $(this).removeClass('is-closing');
            apply();
        });
    }

    // Phones start with the map hidden; the button brings a strip of it back,
    // and the choice is remembered for next time
    function setMapOpen(open) {
        $('body').toggleClass('is-map-open', open);
        $('#map-toggle')
            .attr('aria-pressed', open ? 'true' : 'false')
            .attr('aria-label', open ? 'Hide the map' : 'Show the map')
            .attr('title', open ? 'Hide the map' : 'Show the map');
        try {
            localStorage.setItem('mapOpen', open ? '1' : '0');
        } catch (e) {}
    }

    try {
        setMapOpen(localStorage.getItem('mapOpen') === '1');
    } catch (e) {}

    $('#map-toggle').on('click', function () {
        setMapOpen(!$('body').hasClass('is-map-open'));
    });

    $('#people-toggle').on('click', function () {
        setPeopleOpen(!$('#people').is(':visible'));
    });

    $('#people-close').on('click', function () {
        setPeopleOpen(false);
    });

    $('#people').on('click', '.person--can-message', function () {
        var person = $(this).data('person');
        if (narrowScreen.matches) {
            setPeopleOpen(false);
        }
        openPrivateChat(person.id, person.name);
    });

    // Point out the person's pin on the map
    $('#people').on('mouseenter mouseleave', '.person', function (e) {
        $('.map-pin[data-user-id="' + $(this).attr('data-user-id') + '"]')
            .toggleClass('is-highlighted', e.type === 'mouseenter');
    });

    // The newest message this page has; each tab keeps its own
    var lastChatId = $('#chat').data('last-chat-id');

    // A new version went live: reload, so new messages don't arrive in markup
    // the page's old CSS and JS don't know. Not while something is typed
    var build = $('#chat').attr('data-build');

    function reloadIfUpdated(liveBuild) {
        if (build && liveBuild && liveBuild !== build && $('#textarea-send').val().trim() === '') {
            location.reload();
        }
    }

    // The "Latest" button: shows once you've scrolled up a screen's worth of
    // pixels, counts messages in this tab that arrive meanwhile, and takes you
    // back down
    var newWhileAway = 0;

    function updateJumpButton() {
        var list = $('#chat-list')[0];
        var away = list.scrollHeight - list.scrollTop - list.clientHeight > 200;
        if (!away) newWhileAway = 0;

        var button = $('#jump-latest')[0];
        var label = $('#jump-latest-text');
        var text = newWhileAway === 0 ? 'Latest'
            : newWhileAway + (newWhileAway === 1 ? ' new message' : ' new messages');

        button.hidden = !away;
        if (!away || label.text() === text) {
            label.text(text);
            button.style.width = '';
            return;
        }

        // The pill eases to the new label's width instead of jumping. Going
        // from "Latest" to a count, the words fade in; after that only the
        // number changes, so nothing fades and nothing flickers
        var wasLatest = label.text() === 'Latest';
        var from = button.offsetWidth;
        label.text(text);
        button.style.width = '';
        var to = button.offsetWidth;
        button.style.width = from + 'px';
        button.offsetWidth; // start from the old width
        button.style.width = to + 'px';
        if (wasLatest) {
            label.removeClass('is-changing');
            label[0].offsetWidth;
            label.addClass('is-changing');
        }
    }

    $('#chat-list').on('scroll', updateJumpButton);

    // The button hides once you're down, so keyboard focus moves on to the
    // composer instead of falling back to the top of the page. Taps leave
    // focus alone so a phone's keyboard doesn't pop up
    $('#jump-latest').on('click', function () {
        $('#chat-list').animate({scrollTop: $('#chat-list').prop('scrollHeight')}, SCROLL_DURATION);
        if (this.matches(':focus-visible')) $('#textarea-send').focus();
    });

    // Scrolls down for new messages only if you're at the bottom already, or forced
    // (after you send); someone reading older messages stays where they are
    function checkForMessages(forceScroll) {
        return postForm('/app/get', {after: lastChatId}).then(function (response) {
            reloadIfUpdated(response.headers['x-build']);

            // Two checks can overlap (e.g. right after sending), so skip any already shown
            var chats = $($.parseHTML(response.data)).filter('.chat').filter(function () {
                return $(this).data('id') > lastChatId;
            });
            if (!chats.length) return;

            var list = $('#chat-list')[0];
            var atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;

            showTimes(chats);
            $('#chat-list').append(chats);
            fadeInPhotos(chats.find('.chat__photo'));
            placeMessages(chats);
            addDayDividers();
            // Only once they're shown, so a failure above fetches them again
            lastChatId = chats.last().data('id');

            if (atBottom || forceScroll) {
                $('#chat-list').animate({scrollTop: $('#chat-list').prop('scrollHeight')}, SCROLL_DURATION);
            } else {
                // Only ones in the open tab; the others get a count on their tab
                newWhileAway += chats.filter(function () { return this.style.display !== 'none'; }).length;
                updateJumpButton();
            }
        });
    }

    // Every second, or less often while the tab is hidden
    (function update() {
        checkForMessages()
            .catch(function () {
                // Try again on the next round
            })
            .finally(function () {
                setTimeout(update, document.hidden ? MESSAGES_HIDDEN_INTERVAL : MESSAGES_INTERVAL);
            });
    })();

    // Catch up at once when the tab is shown again
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            checkForMessages().catch(function () {});
        }
    });

    // Send stays off until the message is out, so a second Enter can't post it twice
    var sending = false;

    function updateSendButton() {
        $('#button-send').prop('disabled', sending || $('#textarea-send').val().trim() === '');
    }

    $('#input-container-form').on('submit', function (e) {
        e.preventDefault();
        if (sending) return;

        var bodyText = $('#textarea-send').val().trim();

        var data = {body: bodyText};
        if (activeConversation !== 'everyone') {
            data.to = activeConversation;
        }

        sending = true;
        updateSendButton();

        postForm('/app/send', data)
            .then(function () {
                $('#textarea-send').val("").trigger('input');
                M.textareaAutoResize($('#textarea-send'));
                return checkForMessages(true);
            })
            .catch(function (error) {
                var message = errorMessage(error, 'Your message could not be sent.');
                showToast(message, 'warning');
            })
            .finally(function () {
                sending = false;
                updateSendButton();
            });
    });

    // Enter sends, Shift+Enter starts a new line
    $('#textarea-send').keypress(function (event) {
        if (event.which == 13 && !event.shiftKey) {
            event.preventDefault();
            if (!$('#button-send').prop('disabled')) {
                $("#input-container-form").submit();
            }
        }
    });

    // Grey out Send while the box is empty; show the count near the limit
    $('#textarea-send').on('input', function () {
        var length = this.value.length;
        var max = parseInt(this.getAttribute('maxlength'), 10);

        updateSendButton();
        $('#char-count')
            .prop('hidden', length < max - 100)
            .toggleClass('is-near-limit', length > max - 20)
            .text(length + ' / ' + max);
    });

});
