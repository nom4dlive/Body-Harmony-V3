import telebot
from telebot import types
import os
import time
from functools import wraps
import logging
from datetime import datetime

# Configurações V96.2 (Audit Mode)
# SEGURANÇA: Token deve ser fornecido apenas via variável de ambiente
API_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN")

# Validação de segurança
if not API_TOKEN:
    raise RuntimeError("TELEGRAM_BOT_TOKEN environment variable is required. Never hardcode tokens!")

# Logs robustos com handler de arquivo e console
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(name)s - %(message)s',
    handlers=[
        logging.FileHandler('/var/log/telegram_bot.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

bot = telebot.TeleBot(API_TOKEN, threaded=True)

def retry_on_error(max_attempts=3, delay=2):
    """Decorator para retry em caso de erro de rede"""
    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            for attempt in range(max_attempts):
                try:
                    return func(*args, **kwargs)
                except Exception as e:
                    logger.warning(f"Tentativa {attempt+1} falhou: {e}")
                    if attempt < max_attempts - 1:
                        time.sleep(delay * (attempt + 1))  # Backoff exponencial
                    else:
                        logger.error(f"Falha após {max_attempts} tentativas: {e}")
                        raise
        return wrapper
    return decorator

# --- Keyboards ---

def main_menu():
    m = types.InlineKeyboardMarkup()
    m.add(types.InlineKeyboardButton("🎓 ALUNA", callback_data="aluna"))
    m.add(types.InlineKeyboardButton("💼 LICENCIADA", callback_data="licenciada"))
    m.add(types.InlineKeyboardButton("🩺 CLÍNICO", callback_data="clinico"))
    return m

# --- Handlers ---

@bot.message_handler(commands=['start'])
def send_welcome(message):
    logger.info("START recebido")
    bot.send_message(message.chat.id, "Menu Body Harmony (V3.8.3):", reply_markup=main_menu())

@retry_on_error(max_attempts=3, delay=2)
@bot.callback_query_handler(func=lambda call: True)
def callback_handler(call):
    try:
        logger.info(f"CLICK detectado: {call.data}")
        bot.answer_callback_query(call.id)
        
        if call.data == "aluna":
            bot.send_message(call.message.chat.id, "Área da aluna: https://bodyharmony.com.br/login")
        elif call.data == "clinico":
            bot.send_message(call.message.chat.id, "Suporte Clínico: Equipe notificada.")
        elif call.data == "licenciada":
            bot.send_message(call.message.chat.id, "Área da licenciada: https://bodyharmony.com.br/licenciadas")
    except Exception as e:
        logger.error(f"Erro no callback handler: {e}")
        try:
            bot.send_message(call.message.chat.id, "⚠️ Erro temporário. Tente novamente.")
        except:
            pass

# Handler para mensagens de texto
@bot.message_handler(func=lambda message: True)
def handle_text(message):
    try:
        logger.info(f"Mensagem recebida de {message.chat.id}: {message.text[:50]}")
        bot.send_message(
            message.chat.id,
            "Use /start para acessar o menu principal.",
            reply_markup=main_menu()
        )
    except Exception as e:
        logger.error(f"Erro ao processar mensagem: {e}")

if __name__ == '__main__':
    logger.info("Bot Estabilizado v3.8.3 Iniciando...")
    bot.remove_webhook()
    
    # Polling com backoff inteligente e reconexão automática
    while True:
        try:
            logger.info("Iniciando polling...")
            bot.polling(none_stop=True, interval=5, timeout=60)
        except Exception as e:
            logger.error(f"Erro no polling: {e}")
            logger.info("Reiniciando em 10 segundos...")
            time.sleep(10)
