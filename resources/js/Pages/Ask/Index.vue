<script setup>
import { ref, watch, nextTick, computed, toRaw, onMounted } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
import MarkdownIt from "markdown-it";
import hljs from "highlight.js";
import "highlight.js/styles/github-dark.css";

// Initialisation de l'état avec une meilleure gestion des valeurs par défaut
const page = usePage();

// Remplacer computed par une ref réactive
const conversations = ref(
    Array.isArray(page.props.conversations) ? page.props.conversations : []
);

// Conversion des props en données réactives avec vérification
const models = computed(() => {
    const modelData = toRaw(page.props.models);
    return Array.isArray(modelData) ? modelData : [];
});

const selectedModel = ref(page.props.selectedModel || null);

const messages = ref([]);
const loading = ref(false);
const selectedConversation = ref(null);
const currentConversationId = ref(null);
const editingTitle = ref(null);
const newTitle = ref("");
const flashMessage = ref("");
const flashError = ref("");

// Debug logs
console.log("Models:", toRaw(models.value));
console.log("Conversations:", toRaw(conversations.value));
console.log("Messages:", toRaw(messages.value));

// Initialisation de Markdown
const md = new MarkdownIt({
    highlight: function (str, lang) {
        if (lang && hljs.getLanguage(lang)) {
            try {
                return hljs.highlight(str, { language: lang }).value;
            } catch (__) {
                return "";
            }
        }
        return "";
    },
    html: true,
    breaks: true,
});

// Formulaire avec valeur par défaut sécurisée
const form = useForm({
    message: "",
    model: selectedModel.value || "",
    conversation_id: null,
});

// Gestion des conversations
const selectConversation = async (conversation) => {
    if (!conversation?.id || currentConversationId.value === conversation.id)
        return;

    try {
        loading.value = true;
        currentConversationId.value = conversation.id;
        selectedConversation.value = conversation;

        // Mettre à jour le modèle sélectionné avec celui de la conversation
        if (conversation.model) {
            form.model = conversation.model;
        }

        // Charger les messages de la conversation
        const response = await axios.get(
            route("messages.index", conversation.id)
        );
        if (response?.data?.messages) {
            messages.value = response.data.messages;
            await scrollToBottom();
        }
    } catch (error) {
        console.error("Erreur lors du chargement des messages:", error);
        flashError.value = "Erreur lors du chargement des messages";
    } finally {
        loading.value = false;
    }
};

const createConversation = async () => {
    if (loading.value) return;

    try {
        loading.value = true;
        const response = await axios.post(route("chat.store"));

        if (response?.data?.conversations) {
            conversations.value = response.data.conversations;
            if (response.data.conversation) {
                await selectConversation(response.data.conversation);
            }
        }
    } catch (error) {
        console.error("Erreur lors de la création de la conversation:", error);
        flashError.value = "Erreur lors de la création de la conversation";
    } finally {
        loading.value = false;
    }
};

const updateConversationTitle = async (conversation) => {
    if (!conversation?.id || !newTitle.value?.trim()) {
        newTitle.value = conversation?.title || "";
        editingTitle.value = null;
        return;
    }

    try {
        const response = await axios.post(
            route("chat.updateTitle", conversation.id),
            {
                title: newTitle.value.trim(),
            }
        );

        if (response?.data?.conversation) {
            const index = conversations.value.findIndex(
                (c) => c.id === response.data.conversation.id
            );
            if (index !== -1) {
                conversations.value[index] = response.data.conversation;
            }
        }
    } catch (error) {
        console.error("Erreur lors de la mise à jour du titre:", error);
        flashError.value = "Erreur lors de la mise à jour du titre";
    } finally {
        editingTitle.value = null;
    }
};

// Gestion des messages
const sendMessage = async () => {
    if (
        !form.message.trim() ||
        !selectedConversation.value?.id ||
        loading.value
    )
        return;

    const tempMessage = form.message.trim();
    try {
        loading.value = true;
        form.conversation_id = selectedConversation.value.id;

        messages.value.push({ role: "user", content: tempMessage });
        messages.value.push({
            role: "assistant",
            content: "L'IA réfléchit...",
        });
        await scrollToBottom();

        const processedMessage = await processMessage(tempMessage);

        const response = await axios.post(
            route("messages.store", selectedConversation.value.id),
            {
                message: processedMessage,
                model: form.model,
            }
        );

        if (response.data.messages) {
            messages.value = response.data.messages;
        }

        // Mise à jour de la conversation (avec le titre généré) dans la liste et la sélection
        if (response.data.conversation) {
            const updatedConversation = response.data.conversation;
            const index = conversations.value.findIndex(
                (c) => c.id === updatedConversation.id
            );
            if (index !== -1) {
                conversations.value[index] = updatedConversation;
            }
            if (selectedConversation.value?.id === updatedConversation.id) {
                selectedConversation.value = updatedConversation;
            }
            // Optionnel : réordonner les conversations par date d'activité
            conversations.value.sort(
                (a, b) => new Date(b.last_activity) - new Date(a.last_activity)
            );
        }

        // Mise à jour de toute la liste des conversations
        if (response.data.conversations) {
            conversations.value = response.data.conversations;
        }

        form.reset("message");
        await scrollToBottom();
    } catch (error) {
        // ...gestion d'erreur existante...
        messages.value = messages.value.filter(
            (msg) =>
                msg.content !== "L'IA réfléchit..." &&
                msg.content !== tempMessage
        );
        flashError.value = "Une erreur est survenue lors de l'envoi du message";
    } finally {
        loading.value = false;
    }
};

// Utilitaires
const scrollToBottom = async () => {
    await nextTick();
    const chatContainer = document.querySelector(".chat-container");
    if (chatContainer) {
        chatContainer.scrollTop = chatContainer.scrollHeight;
    }
};

// Ajoutez cette fonction pour formater la date
const formatDate = (dateString) => {
    if (!dateString) return "";
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return "";
    return date.toLocaleDateString("fr-FR", {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
};

// Ajout des refs pour les instructions personnalisées
const showInstructionsModal = ref(false);
const customInstruction = ref(
    page.props.customInstruction || {
        about_user: "",
        preference: "",
    }
);

// Fonction pour sauvegarder les instructions
const saveInstructions = async () => {
    try {
        loading.value = true;
        const response = await axios.post(route("custom-instructions.store"), {
            about_user: customInstruction.value.about_user,
            preference: customInstruction.value.preference,
        });

        if (response.data.instruction) {
            customInstruction.value = response.data.instruction;
            showInstructionsModal.value = false;
            flashMessage.value = "Instructions personnalisées sauvegardées";
        }
    } catch (error) {
        flashError.value = "Erreur lors de la sauvegarde des instructions";
    } finally {
        loading.value = false;
    }
};

// Observers
watch(
    messages,
    () => {
        scrollToBottom();
    },
    { deep: true }
);

watch(selectedModel, (newModel) => {
    if (newModel) {
        form.model = newModel;
    }
});
// Ajout d'un watcher sur le modèle pour sauvegarder le choix utilisateur et celui de la conversation (si sélectionnée)
watch(
    () => form.model,
    async (newModel, oldModel) => {
        if (newModel && newModel !== oldModel) {
            try {
                await axios.post(route("user.updateModel"), {
                    model: newModel,
                    conversation_id: selectedConversation.value?.id || null,
                });
            } catch (error) {
                console.error("Erreur lors de la sauvegarde du modèle:", error);
            }
        }
    }
);

const commands = ref([]);
const showCommandsModal = ref(false);

const newCommand = ref({
    name: "",
    command: "",
    description: "",
    prompt: "",
});

const saveCommand = async () => {
    try {
        loading.value = true;
        const response = await axios.post(
            route("custom-commands.store"),
            newCommand.value
        );
        if (response.data.command) {
            commands.value.push(response.data.command);
            newCommand.value = {
                name: "",
                command: "",
                description: "",
                prompt: "",
            };
            flashMessage.value = "Commande personnalisée sauvegardée";
        }
    } catch (error) {
        flashError.value = "Erreur lors de la sauvegarde de la commande";
    } finally {
        loading.value = false;
    }
};

// Charger les commandes au démarrage
const loadCommands = async () => {
    try {
        const response = await axios.get(route("custom-commands.index"));
        commands.value = response.data.commands;
    } catch (error) {
        console.error("Erreur lors du chargement des commandes:", error);
    }
};

// Vérifier si le message commence par une commande
const processMessage = async (message) => {
    if (message.startsWith("/")) {
        const command = commands.value.find((cmd) =>
            message.startsWith(cmd.command)
        );

        if (command) {
            // Remplacer la commande par son prompt
            return (
                command.prompt +
                " " +
                message.slice(command.command.length).trim()
            );
        }
    }
    return message;
};

// Charger les commandes au montage du composant
onMounted(() => {
    loadCommands();
});
</script>

<template>
    <div class="h-screen w-screen flex bg-gray-900 text-white">
        <!-- Sidebar avec scroll -->
        <aside
            class="w-1/4 bg-gray-800 p-4 flex flex-col border-r border-gray-700"
        >
            <h2 class="text-xl font-bold mb-4">Conversations</h2>

            <button
                @click="createConversation"
                class="p-2 mb-4 bg-blue-500 text-white rounded hover:bg-blue-600 transition flex items-center justify-center"
                :disabled="loading"
            >
                <span v-if="!loading">+ Nouvelle conversation</span>
                <span v-else>Chargement...</span>
            </button>

            <!-- Conteneur avec scroll pour la liste des conversations -->
            <div class="flex-1 overflow-y-auto min-h-0">
                <div v-if="conversations?.length" class="space-y-2">
                    <div
                        v-for="conversation in conversations"
                        :key="conversation?.id"
                        class="cursor-pointer p-3 rounded-lg transition-colors duration-200"
                        :class="{
                            'bg-gray-700':
                                selectedConversation?.id === conversation?.id,
                            'hover:bg-gray-700':
                                selectedConversation?.id !== conversation?.id,
                        }"
                    >
                        <div
                            class="flex justify-between items-center"
                            @click="selectConversation(conversation)"
                        >
                            <div v-if="editingTitle === conversation?.id">
                                <input
                                    v-model="newTitle"
                                    @blur="
                                        updateConversationTitle(conversation)
                                    "
                                    @keyup.enter="
                                        updateConversationTitle(conversation)
                                    "
                                    class="bg-gray-600 text-white px-2 py-1 rounded w-full"
                                    ref="titleInput"
                                />
                            </div>
                            <div
                                v-else
                                class="font-medium"
                                @dblclick="
                                    () => {
                                        editingTitle = conversation?.id;
                                        newTitle = conversation?.title || '';
                                        $nextTick(() => {
                                            $refs.titleInput?.focus();
                                        });
                                    }
                                "
                            >
                                {{
                                    conversation?.title ||
                                    "Nouvelle conversation"
                                }}
                            </div>
                            <div class="text-sm text-gray-400">
                                {{ formatDate(conversation?.last_activity) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="text-gray-400 text-center py-4">
                    Aucune conversation
                </div>
            </div>

            <!-- Bouton instructions en bas fixe -->
            <button
                @click="showInstructionsModal = true"
                class="w-full p-2 mt-4 bg-gray-700 text-white rounded hover:bg-gray-600 transition"
            >
                Instructions personnalisées
            </button>

            <!-- Ajouter un bouton pour gérer les commandes -->
            <button
                @click="showCommandsModal = true"
                class="w-full p-2 mt-2 bg-gray-700 text-white rounded hover:bg-gray-600 transition"
            >
                Commandes personnalisées
            </button>
        </aside>

        <!-- Zone principale -->
        <main class="flex-1 flex flex-col h-full">
            <div class="w-full h-full bg-gray-800 p-6 flex flex-col">
                <!-- Sélecteur de modèle -->
                <div class="mb-4">
                    <label class="block font-medium text-gray-200 mb-2"
                        >Modèle utilisé :</label
                    >
                    <select
                        v-model="form.model"
                        class="w-full p-2 border rounded bg-gray-700 text-white"
                        :disabled="loading"
                    >
                        <option
                            v-for="model in models"
                            :key="model?.id"
                            :value="model?.id"
                        >
                            {{ model?.name }}
                        </option>
                    </select>
                </div>

                <h1 class="text-2xl font-semibold text-center mb-4">
                    Que puis-je faire pour vous ?
                </h1>

                <!-- Messages -->
                <div
                    class="chat-container flex-1 bg-gray-700 p-4 rounded-lg overflow-y-auto border mb-4"
                >
                    <div v-if="messages?.length">
                        <div
                            v-for="(msg, index) in messages"
                            :key="index"
                            class="mb-4 last:mb-0"
                        >
                            <div
                                class="flex"
                                :class="
                                    msg?.role === 'user'
                                        ? 'justify-end'
                                        : 'justify-start'
                                "
                            >
                                <div
                                    class="max-w-[80%] p-3 rounded-lg shadow-md"
                                    :class="
                                        msg?.role === 'user'
                                            ? 'bg-blue-500'
                                            : 'bg-gray-600'
                                    "
                                >
                                    <strong
                                        >{{
                                            msg?.role === "user"
                                                ? "Vous"
                                                : "Assistant"
                                        }}:</strong
                                    >
                                    <div
                                        class="mt-1 prose prose-invert max-w-none"
                                        v-html="md.render(msg?.content || '')"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-gray-400 text-center py-4">
                        Commencez une conversation...
                    </div>
                </div>

                <!-- Messages d'erreur -->
                <div
                    v-if="flashError"
                    class="mb-4 p-3 bg-red-500 text-white rounded"
                >
                    {{ flashError }}
                </div>

                <!-- Formulaire -->
                <form @submit.prevent="sendMessage" class="flex space-x-2">
                    <input
                        v-model="form.message"
                        type="text"
                        class="flex-1 p-3 border rounded-lg focus:ring focus:ring-blue-300 bg-gray-700 text-white"
                        placeholder="Posez votre question..."
                        :disabled="loading || !selectedConversation"
                    />
                    <button
                        type="submit"
                        class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="
                            loading ||
                            !selectedConversation ||
                            !form.message?.trim()
                        "
                    >
                        {{ loading ? "Envoi..." : "Envoyer" }}
                    </button>
                </form>
            </div>
        </main>

        <!-- Modal Instructions Personnalisées -->
        <div
            v-if="showInstructionsModal"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4"
        >
            <div class="bg-gray-800 rounded-lg p-6 max-w-2xl w-full">
                <h2 class="text-xl font-bold mb-4">
                    Instructions Personnalisées
                </h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">
                            Que souhaitez-vous que l'IA sache à propos de vous ?
                        </label>
                        <textarea
                            v-model="customInstruction.about_user"
                            class="w-full h-32 bg-gray-700 rounded p-2"
                            placeholder="Ex: Je suis développeur PHP avec 5 ans d'expérience..."
                        ></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2">
                            Comment souhaitez-vous que l'IA vous réponde ?
                        </label>
                        <textarea
                            v-model="customInstruction.preference"
                            class="w-full h-32 bg-gray-700 rounded p-2"
                            placeholder="Ex: Réponses concises avec des exemples de code..."
                        ></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <button
                        @click="showInstructionsModal = false"
                        class="px-4 py-2 bg-gray-600 rounded hover:bg-gray-500"
                    >
                        Annuler
                    </button>
                    <button
                        @click="saveInstructions"
                        class="px-4 py-2 bg-blue-500 rounded hover:bg-blue-600"
                        :disabled="loading"
                    >
                        {{ loading ? "Sauvegarde..." : "Sauvegarder" }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal pour les commandes -->
        <div
            v-if="showCommandsModal"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4"
        >
            <div class="bg-gray-800 rounded-lg p-6 max-w-2xl w-full">
                <h2 class="text-xl font-bold mb-4">Commandes Personnalisées</h2>

                <!-- Liste des commandes existantes -->
                <div class="mb-6 space-y-4">
                    <div
                        v-for="cmd in commands"
                        :key="cmd.id"
                        class="bg-gray-700 p-4 rounded"
                    >
                        <div class="font-bold">{{ cmd.name }}</div>
                        <div class="text-gray-400">{{ cmd.command }}</div>
                        <div class="text-sm">{{ cmd.description }}</div>
                    </div>
                </div>

                <!-- Formulaire pour ajouter une nouvelle commande -->
                <form @submit.prevent="saveCommand" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-2"
                            >Nom de la commande</label
                        >
                        <input
                            type="text"
                            v-model="newCommand.name"
                            class="w-full bg-gray-700 rounded p-2"
                            placeholder="Ex: Météo"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2"
                            >Commande (/)</label
                        >
                        <input
                            type="text"
                            v-model="newCommand.command"
                            class="w-full bg-gray-700 rounded p-2"
                            placeholder="Ex: /meteo"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2"
                            >Description</label
                        >
                        <input
                            type="text"
                            v-model="newCommand.description"
                            class="w-full bg-gray-700 rounded p-2"
                            placeholder="Ex: Affiche la météo pour une ville"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2"
                            >Prompt</label
                        >
                        <textarea
                            v-model="newCommand.prompt"
                            class="w-full h-32 bg-gray-700 rounded p-2"
                            placeholder="Ex: Donne la météo pour la ville de"
                        ></textarea>
                    </div>
                </form>

                <div class="mt-6 flex justify-end space-x-3">
                    <button
                        @click="showCommandsModal = false"
                        class="px-4 py-2 bg-gray-600 rounded hover:bg-gray-500"
                    >
                        Fermer
                    </button>
                    <button
                        @click="saveCommand"
                        class="px-4 py-2 bg-blue-500 rounded hover:bg-blue-600"
                        :disabled="loading"
                    >
                        Ajouter une commande
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
