// Board logic shared by the game page and the start page.
// The page defines moved() (called after every click) and categoryDone(button) (called when a category is complete).

var rand = Math.random;  // The game page replaces it with a seeded generator, see playSeed
var size = 0;  // Number of categories, and of items per category
var score = 0;
var mistakes = 0;
var selected = 0;  // The currently selected button, if any.


function deselect() {
  if (selected != 0) {
    selected.style = "";
    selected = 0;
    moved();
  }
}



function stringToLightColor(str) {
  let hash = 0;
  for (let i = 0; i < str.length; i++) {
    hash = str.charCodeAt(i) + ((hash << 5) - hash);
  }

  const h = Math.abs(hash) % 360;
  const s = 70;
  const l = 80 + (Math.abs(hash) % 10);

  const lDev = l / 100;
  const a = (s * Math.min(lDev, 1 - lDev)) / 100;

  const f = (n) => {
    const k = (n + h / 30) % 12;
    const color = lDev - a * Math.max(Math.min(k - 3, 9 - k, 1), -1);
    return Math.round(255 * color).toString(16).padStart(2, "0");
  };

  return `#${f(0)}${f(8)}${f(4)}`;
}



function finishCategory(b) {
  b.innerHTML  =  '<b>' + b.category + '</b>';
  b.disabled = true;
  b.style.background= stringToLightColor(b.category);
}



function shuffleArray(array) {
  for (let i = array.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    [array[i], array[j]] = [array[j], array[i]];
  }
}



//wordlist: (word, category) pairs
function putWordsInBoard(wordlist) {
  shuffleArray(wordlist);
  var buttons = document.getElementById("board").children;
  for (let i = 0; i < buttons.length; i++) {
    buttons[i].textContent = wordlist[i][0];
    buttons[i].category = wordlist[i][1];
    buttons[i].cluster = [buttons[i].textContent];
  }
}



function setUpBoard(n) {
  // Just sets up the dom elements; does not put words in them.
  size = n;
  score = 0;
  mistakes = 0;
  var b = document.getElementById("board");
  b.style.setProperty('--n', n);
  for (let i = 0; i < n * n; i++) {
       const button = document.createElement('button');
       button.onclick = function() {
           if (selected == button) {
             deselect();
             return;
           }
           button.style = "filter: invert(100%)";
           if (selected == 0) {
               selected = button;
               moved();
               return;
           }
           if (button.category == selected.category) {
             firstbut = button;
             secondbut = selected;

             // Deselect
             selected.style = "";
             button.style = "";
             selected = 0;
             score += 1;

             // Merge
             firstbut.cluster = firstbut.cluster.concat(secondbut.cluster);
             if (firstbut.cluster.length == 2) {
               firstbut.innerHTML = '<b>' + firstbut.cluster.join('; ') + '</b>';
             } else {
               firstbut.innerHTML = '<b>' + firstbut.cluster.slice(0, 2).join(', ') + ', ... <span class="red">[' + firstbut.cluster.length + ']</span></b>';
               firstbut.title = firstbut.cluster.join('\n');
             }
             if (firstbut.cluster.length == size) {
                 finishCategory(firstbut);
                 categoryDone(firstbut);
             }

             // Remove the button for the eliminated word.
             secondbut.title = '';
                 secondbut.textContent = '';
                 secondbut.disabled = true;

                 // Keep its slot as an empty hole.
                 secondbut.classList.add('gone');

           } else {
             mistakes = mistakes + 1;
             // Shake it
             button.classList.add('shake');
             selected.classList.add('shake');

             button.addEventListener('animationend', () => {
               button.classList.remove('shake');
               button.style = "";
             }, { once: true });

             old_selected = selected;
             old_selected.addEventListener('animationend', () => {
               old_selected.classList.remove('shake');
               old_selected.style = "";
             }, { once: true });

             selected = 0;
           }
           moved();
       };
       b.appendChild(button);
  }
}
