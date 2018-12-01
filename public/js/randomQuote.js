$(document).ready(function () {

    var quoteMaster = [
        {
            quote: "Never forget what you are, for surely the world will not. Make it your strength. Then it can never be your weakness. Armor yourself in it, and it will never be used to hurt you.",
            name: 'Tyrion Lannister',
        },

        {
            quote: "I am a Khaleesi of the Dothraki! I am the wife of the great Khal and I carry his son inside me! The next time you raise a hand to me will be the last time you have hands.",
            name: 'Daenerys Targaryen'
        },

        {
            quote: "He who passes the sentence, must swing the sword.",
            name: "Eddard Stark"
        },

        {
            quote: "People often claim to hunger for truth, but seldom like the taste when it’s served up",
            name: 'Tyrion Lannister'
        },

        {
            quote: "When the snows fall and the white winds blow, the lone wolf dies, but the pack survives.",
            name: 'Eddard Stark'
        },

        {
            quote: "Each bruise is a lesson. Each Lesson makes us better.",
            name: 'Syrio Forel'
        },

        {
            quote: "Nothing isn't better or worse than anything. Nothing is just nothing.",
            name: "Arya Stark"
        },

        {
            quote: "It's not easy being drunk all the time. If it were easy, everyone would do it.",
            name: 'Tyrion Lannister'
        },

        {
            quote: "Different roads sometimes lead to the same castle",
            name: 'Jon Snow'
        },

        {
            quote: "There’s no shame in fear, my father told me, what matters is how we face it.",
            name: 'Jon Snow'
        },

        {
            quote: "There is a beast in every man and it stirs when you put a sword in his hand.",
            name: 'Ser Jorah Mormont'
        },

        {
            quote: "All men must die, but we are not men.",
            name: "Daenerys Targaryen"
        },

        {quote: "I'm a monster, as well as a dwarf. You should charge me double", name: "Tyrion Lannister"},

        {
            quote: "I will hurt you for this. A day will come when you think you are safe and happy, and your joy will turn to ashes in your mouth. And you will know the debt is paid",
            name: "Tyrion Lannister"
        },

        {
            quote: "All my life men like you have sneered at me. And all my life I've been knocking men like you into the dust.",
            name: "Lady Brienne of Tarth"
        },

        {
            quote: "I'll tell you what. I'm going to give you a present. After I raise my armies, and kill your traitor brother, I'll give you his head as well.",
            name: "Joffrey Baratheon"
        },

        {
            quote: "it's the family name that lives on. It's all that lives on. Not your personal glory, not your honor... but family.",
            name: "Tywin Lannister"
        },

        {
            quote: "When you play the game of thrones, you win or you die.",
            name: "Cersei Lannister"
        },

        {
            quote: "Never forget what you are, the rest of the world will not. Wear it like armor and it can never be used to hurt you",
            name: "Tyrion Lannister"
        },

        {
            quote: "It's hard to put a leash on a dog once you've put a crown on its head.",
            name: "Tyrion Lannister"
        },

        {
            quote: "I wish I was the monster you think I am! I wish I had enough poison for the whole pack of you! I would gladly give my life to watch you all swallow it!",
            name: "Tyrion Lannister"
        },

        {
            quote: "I will hurt you for this. A day will come when you think you're safe and happy, and your joy will turn to ashes in your mouth. Then you will know the debt is paid.",
            name: "Tyrion Lannister"
        },

        {
            quote: "Tell me, if I stabbed the Mad King in the belly instead of the back, would you admire me more?",
            name: "Jamie Lannister"
        },

        {
            quote: "So many vows. They make you swear and swear. Defend the king. Obey the king. Obey your father. Protect the innocent. Defend the weak. What if your father despises the king? What if the king massacres the innocent?",
            name: "Jamie Lannister"
        },

        {
            quote: "It's a shame the throne isn't made out of cocks, they'd have never got him off it.",
            name: "Jamie Lannister"
        },

        {
            quote: "You think my life is such a precious thing to me, that I would trade my honor for a few more years...of what?",
            name: "Eddard Stark"
        },

        {
            quote: "It wasn't for the murder that the Gods cursed the Rat Cook, or for serving the King's son in a pie. He killed a guest beneath his roof. That's something the Gods can't forgive.",
            name: "Bran Stark"
        },

        {
            quote: "aime and I are more than brother and sister. We are one person in two bodies. We shared a womb together. He came into this world holding my foot, our old maester said. When he is in me, I feel... whole.",
            name: "Cersie Lannister"
        },

        {
            quote: "A woman's life is nine parts mess to one part magic, you'll learn that soon enough... and the parts that look like magic often turn out to be messiest of all.",
            name: "Cersie Lannister"
        },

        {
            quote: "Love is poison. A sweet poison, yes, but it will kill you all the same.",
            name: "Cersie Lannister"
        },

        {
            quote: "The only way to keep your people loyal is to make certain they fear you more than they do the enemy.",
            name: "Cersie Lannister"
        },

        {
            quote: "I am a lioness. I will not cringe for them.",
            name: "Cersie Lannister"
        }
    ];
    num = quoteMaster.length;

    // Auto-text generator
        $(document).keydown(function (e) {

            if (e.keyCode == 89 && e.ctrlKey) {
                for (var i = 0; i < num; i++) {

                    var x = Math.floor(Math.random() * num);
                    var quoteText = quoteMaster[x].quote;
                    var quoteTextBy = quoteMaster[x].name;
                }

                $('#textarea-send').val(quoteText + " - " + quoteTextBy);
                M.textareaAutoResize($('#textarea-send'));

                //$("#input-container-form").submit();
            }
        });


    }
);