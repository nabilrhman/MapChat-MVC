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

    // Tabs from the history just appear; ones opened later ease in
    var tabsReady = false;

    function tabFor(conversation) {
        return $('#chat-tabs .chat-tab[data-conversation="' + conversation + '"]').not('.tab-leave');
    }

    function addTab(conversation, name) {
        var tab = tabFor(conversation);
        if (!tab.length) {
            tab = $('<a class="chat-tab" href="#"></a>')
                .attr('data-conversation', conversation)
                .append($('<span class="tab-online" title="Online"></span>').prop('hidden', !onlineIds[conversation]))
                .append($('<span class="tab-name"></span>').text(name).attr('title', name))
                .append('<span class="unread" hidden></span>')
                .append('<span class="close-tab" title="Close">&times;</span>')
                .toggleClass('tab-enter', tabsReady);
            $('#chat-tabs').append(tab);
        }
        return tab;
    }

    function showConversation(conversation) {
        activeConversation = String(conversation);

        $('#chat-tabs .chat-tab').removeClass('active');
        var tab = tabFor(activeConversation).addClass('active');
        tab.find('.unread').prop('hidden', true).text('');
        tab[0].scrollIntoView({block: 'nearest', inline: 'nearest'});

        $('#chat-list .chat').each(function () {
            $(this).toggle(String($(this).data('conversation')) === activeConversation);
        });

        // Restart the fade (reading offsetWidth makes the browser see the removal)
        var list = $('#chat-list').removeClass('tab-in');
        list[0].offsetWidth;
        list.addClass('tab-in');

        var name = tab.find('.tab-name').text();
        var isPrivate = activeConversation !== 'everyone';

        $('#private-note').prop('hidden', !isPrivate);
        $('#private-note-text').text('Private chat with ' + name);
        $('#textarea-send').attr('placeholder', isPrivate ? 'Message ' + name : 'Type your message');
        addDayDividers();
        $('.chat-list').scrollTop($('.chat-list').prop('scrollHeight'));
    }

    // Uploaded photos fade in over a soft circle once loaded; letter avatars
    // are inline images and show at once
    function fadeInPhotos(images) {
        images.each(function () {
            var img = this;
            if (img.complete || img.src.indexOf('data:') === 0) return;

            var frame = $(img).parent().addClass('photo-loading');
            $(img).one('load error', function () {
                frame.removeClass('photo-loading');
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
        chats.find('.country-name').each(function () {
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

            var tip = $(this).find('.sender-time');
            if (!tip.length) return;

            var info = $(this).find('.time');
            // Always midnight on Null Island, where the time zones meet
            var local = info.data('country') === NULL_ISLAND
                ? {text: '00:00', hour: 0}
                : senderTime(date, info.data('timezone'), parseFloat(info.data('lng')));
            var day = local.hour >= 6 && local.hour < 18;

            tip.find('.daylight').text(day ? 'wb_sunny' : 'nights_stay').addClass(day ? 'day' : 'night');
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
                $('<div class="day-divider"><span></span></div>').insertBefore(this)
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
                var unread = tab.find('.unread');
                unread.text((parseInt(unread.text(), 10) || 0) + 1).prop('hidden', false);
            }
        });
    }

    showTimes($('#chat-list .chat'));
    fadeInPhotos($('#chat-list .avatar'));
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

    $('#chat-tabs').on('click', '.close-tab', function (e) {
        e.stopPropagation();
        var tab = $(this).closest('.chat-tab');
        if (tab.hasClass('active')) {
            showConversation('everyone');
        }
        fadeOut(tab, 'tab-leave');
    });

    function openPrivateChat(userId, name) {
        addTab(userId, name);
        showConversation(userId);
        $('#textarea-send').focus();
    }

    $('#chat-list').on('click', '.can-message', function () {
        openPrivateChat($(this).data('user-id'), $(this).data('user-name'));
    });

    function timeAgo(minutes) {
        if (minutes < 60) return minutes + ' min ago';
        return Math.floor(minutes / 60) + ' h ago';
    }

    function personRow(person) {
        var isMe = person.id === myId;

        var row = $('<div class="person"></div>')
            .toggleClass('online', person.is_online == 1)
            .toggleClass('can-message', !isMe)
            .attr('data-user-id', person.id)
            .attr('title', isMe ? '' : 'Chat privately with ' + person.name);

        $('<div class="person-avatar"></div>')
            .append($('<img alt="">').attr('src', person.avatar_src))
            .appendTo(row);
        // Online: flag and city. Recently here: how long ago
        var detail = $('<span class="person-detail"></span>');
        if (person.is_online != 1) {
            detail.text(timeAgo(person.minutes_ago));
        } else {
            if (person.country) {
                detail.append($('<span class="fi"></span>').addClass('fi-' + person.country.toLowerCase()));
            }
            detail.append($('<span class="person-city"></span>').text(person.city || ''));
        }

        $('<div class="person-text"></div>')
            .append($('<span class="person-name"></span>').text(person.name + (isMe ? ' (you)' : '')))
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
        $('.map-pin').removeClass('highlight');

        // A green dot on the tab of each private chat partner who's online;
        // it fades only when that changes
        onlineIds = {};
        online.forEach(function (p) { onlineIds[p.id] = true; });
        $('#chat-tabs .tab-online').each(function () {
            var dot = $(this);
            var isOnline = !!onlineIds[dot.closest('.chat-tab').data('conversation')];
            if (isOnline === !dot.prop('hidden')) return;

            if (isOnline) {
                dot.prop('hidden', false);
            } else {
                dot.addClass('dot-leave').one('animationend', function () {
                    dot.prop('hidden', true).removeClass('dot-leave');
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
        section.children('.person').not('.person-leave').each(function (index) {
            old[$(this).attr('data-user-id')] = {row: $(this), index: index};
        });

        var rows = people.map(function (person) {
            var row = personRow(person).toggleClass('person-enter', !old[person.id]);
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
            fadeOut(gone.row, 'person-leave');
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
    $('body').addClass('panels-in');

    // A drawer on narrow screens, a column that can be closed on wide ones
    var narrowScreen = window.matchMedia(NARROW_SCREEN);

    function setPeopleOpen(open) {
        var apply = function () {
            if (narrowScreen.matches) {
                $('#people').toggleClass('open', open);
            } else {
                $('body').toggleClass('people-closed', !open);
            }
        };

        if (open) {
            apply();
            return;
        }

        // Slide out first, then hide
        $('#people').addClass('slide-out').one('animationend', function () {
            $(this).removeClass('slide-out');
            apply();
        });
    }

    $('#people-toggle').on('click', function () {
        setPeopleOpen(!$('#people').is(':visible'));
    });

    $('#people-close').on('click', function () {
        setPeopleOpen(false);
    });

    $('#people').on('click', '.person.can-message', function () {
        var person = $(this).data('person');
        if (narrowScreen.matches) {
            setPeopleOpen(false);
        }
        openPrivateChat(person.id, person.name);
    });

    // Point out the person's pin on the map
    $('#people').on('mouseenter mouseleave', '.person', function (e) {
        $('.map-pin[data-user-id="' + $(this).attr('data-user-id') + '"]')
            .toggleClass('highlight', e.type === 'mouseenter');
    });

    // The newest message this page has; each tab keeps its own
    var lastChatId = $('#chat').data('last-chat-id');

    // Scrolls down for new messages only if you're at the bottom already, or forced
    // (after you send); someone reading older messages stays where they are
    function checkForMessages(forceScroll) {
        return postForm('/app/get', {after: lastChatId}).then(function (response) {
            // Two checks can overlap (e.g. right after sending), so skip any already shown
            var chats = $($.parseHTML(response.data)).filter('.chat').filter(function () {
                return $(this).data('id') > lastChatId;
            });
            if (!chats.length) return;

            var list = $('#chat-list')[0];
            var atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;

            showTimes(chats);
            $(".chat-list").append(chats);
            fadeInPhotos(chats.find('.avatar'));
            placeMessages(chats);
            addDayDividers();
            // Only once they're shown, so a failure above fetches them again
            lastChatId = chats.last().data('id');

            if (atBottom || forceScroll) {
                $(".chat-list").animate({scrollTop: $(".chat-list").prop("scrollHeight")}, SCROLL_DURATION);
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

        var bodyText = $('.materialize-textarea').val().trim();

        var data = {body: bodyText};
        if (activeConversation !== 'everyone') {
            data.to = activeConversation;
        }

        sending = true;
        updateSendButton();

        postForm('/app/send', data)
            .then(function () {
                $('.materialize-textarea').val("").trigger('input');
                M.textareaAutoResize($('.materialize-textarea'));
                return checkForMessages(true);
            })
            .catch(function (error) {
                var message = errorMessage(error, 'Your message could not be sent.');
                M.toast({html: $('<div>').text(message).html()});
            })
            .finally(function () {
                sending = false;
                updateSendButton();
            });
    });

    // Enter sends, Shift+Enter starts a new line
    $(".materialize-textarea").keypress(function (event) {
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
            .toggleClass('over-soon', length > max - 20)
            .text(length + ' / ' + max);
    });

});
